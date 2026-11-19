<?php

namespace App\Services\Email\Providers;

use App\Contracts\Email\CanMarkEmailAsRead;
use App\Contracts\Email\EmailProviderInterface;
use App\Models\EmailAccount;
use App\Models\JarvisSetting;
use App\Services\Email\EmailMessage;
use App\Services\Email\EmailNormalizer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Email as SymfonyEmail;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

class ImapEmailProvider implements CanMarkEmailAsRead, EmailProviderInterface
{
    private Client $client;

    /** Active/default mailbox from Settings → Email Accounts. */
    private ?EmailAccount $account;

    /** Normalized email configuration. */
    private array $cfg;

    /** Full JC Settings row (permissions, tone, etc.). */
    private JarvisSetting $settings;

    public function __construct(private EmailNormalizer $normalizer, ?int $accountId = null)
    {
        // Keep JarvisSetting for JC permissions and general settings.
        $this->settings = JarvisSetting::current();

        // Email configuration comes ONLY from Settings → Email Accounts.
        $accountQuery = EmailAccount::query()->where('is_active', true);
        $this->account = $accountId
            ? $accountQuery->whereKey($accountId)->first()
            : $accountQuery->where('is_default', true)->first();

        // Normalize the selected mailbox configuration.
        $this->cfg = [
            'address' => $this->account?->email,

            'imap_host' => $this->account?->imap_host,
            'imap_port' => $this->account?->imap_port,
            'imap_enc' => $this->account?->imap_encryption,
            'imap_username' => $this->account?->imap_username,
            'imap_password' => $this->account?->imap_password,

            'smtp_host' => $this->account?->smtp_host,
            'smtp_port' => $this->account?->smtp_port,
            'smtp_enc' => $this->account?->smtp_encryption,
            'smtp_username' => $this->account?->smtp_username,
            'smtp_password' => $this->account?->smtp_password,
        ];

        // Build IMAP client.
        $manager = new ClientManager;

        $this->client = $manager->make([
            'host' => $this->cfg['imap_host'] ?? '',
            'port' => (int) ($this->cfg['imap_port'] ?? 993),
            'encryption' => ($this->cfg['imap_enc'] ?? 'ssl') === 'none'
                ? false
                : ($this->cfg['imap_enc'] ?? 'ssl'),
            'validate_cert' => true,
            'username' => $this->cfg['imap_username'] ?? '',
            'password' => $this->cfg['imap_password'] ?? '',
            'protocol' => 'imap',
            'timeout' => 15,
        ]);

    }

    /* =========================================================
       HELPERS
    ========================================================= */

    /**
     * Is this action enabled in Settings → Scope?
     */
    private function can(string $action): bool
    {
        return in_array(
            $action,
            $this->settings->allowed_actions ?? [],
            true
        );
    }

    private function denied(string $what): array
    {
        return [
            'success' => false,
            'error' => "{$what} is turned off in Settings.",
        ];
    }

    /** Whether sending new emails is enabled in Settings → Scope. */
    public function canSendNewEmails(): bool
    {
        return $this->can('send_emails');
    }

    /**
     * Determine whether a real mailbox has been configured.
     *
     * Placeholder/default credentials are treated as unconfigured.
     */
    public function isConfigured(): bool
    {
        if (! $this->account) {
            return false;
        }

        if (! $this->account->is_active) {
            return false;
        }

        $address = trim((string) ($this->cfg['address'] ?? ''));
        $username = trim((string) ($this->cfg['imap_username'] ?? ''));
        $password = (string) ($this->cfg['imap_password'] ?? '');
        $host = trim((string) ($this->cfg['imap_host'] ?? ''));
        $port = (int) ($this->cfg['imap_port'] ?? 0);

        if (
            $address === '' ||
            $username === '' ||
            $password === '' ||
            $host === '' ||
            $port <= 0
        ) {
            return false;
        }

        return ! in_array(
            strtolower($address),
            [
                'you@yourdomain.com',
                'your@email.com',
                'you@example.com',
            ],
            true
        );
    }

    /**
     * Connect to IMAP.
     */
    private function connect(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Email is not configured. Open Settings → Email and enter the mailbox address and password.'
            );
        }

        $startedAt = microtime(true);

        Log::debug('JARVIS IMAP connecting', [
            'host' => $this->cfg['imap_host'],
            'port' => $this->cfg['imap_port'],
            'timeout' => 15,
        ]);

        try {
            $this->client->connect();

            Log::debug('JARVIS IMAP connected', [
                'host' => $this->cfg['imap_host'],
                'duration_ms' => round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (Throwable $exception) {
            Log::error('JARVIS IMAP connection failed', [
                'host' => $this->cfg['imap_host'],
                'port' => $this->cfg['imap_port'],
                'duration_ms' => round((microtime(true) - $startedAt) * 1000),
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
    /* =========================================================
       CONNECTION TEST
    ========================================================= */

    public function testConnection(): array
    {
        $this->connect();

        try {
            $folders = $this->client->getFolders();

            return [
                'connected' => true,
                'email' => $this->cfg['address'],
                'folders' => $folders->count(),
            ];
        } finally {
            $this->client->disconnect();
        }
    }

    /* =========================================================
       LATEST EMAILS
    ========================================================= */

    /**
     * Get the latest emails from INBOX.
     *
     * Only fetch the requested number of messages to keep
     * IMAP requests fast.
     */
    public function latestEmails(int $limit = 10): array
    {
        if (! $this->can('read_emails')) {
            return $this->denied('Reading emails');
        }

        $this->connect();

        try {
            $folder = $this->client->getFolder('INBOX');

            $messages = $folder
                ->messages()
                ->all()
                ->setFetchOrder('desc')
                ->limit($limit)
                ->get();

            $emails = [];

            foreach ($messages as $message) {
                $date = $message->getDate();

                $timestamp = null;

                if ($date) {
                    try {
                        $timestamp = $date->getTimestamp();
                    } catch (Throwable) {
                        $timestamp = null;
                    }
                }

                $emails[] = [
                    'uid' => $message->getUid(),
                    'from' => $message->getFrom()->first()?->mail,
                    'from_name' => $message->getFrom()->first()?->personal,
                    'subject' => $message->getSubject()->toString(),
                    'date' => $date?->toString(),
                    'timestamp' => $timestamp,
                    'seen' => $message->getFlags()->contains('Seen'),
                ];
            }

            /*
             * Sort newest first.
             * Primary: Email Date header. Fallback: UID.
             */
            usort($emails, function (array $a, array $b): int {
                $dateA = $a['timestamp'] ?? 0;
                $dateB = $b['timestamp'] ?? 0;

                if ($dateA !== $dateB) {
                    return $dateB <=> $dateA;
                }

                return ((int) ($b['uid'] ?? 0))
                    <=> ((int) ($a['uid'] ?? 0));
            });

            $emails = array_slice($emails, 0, $limit);

            /*
             * timestamp is only needed internally.
             */
            $emails = array_map(function (array $email): array {
                unset($email['timestamp']);

                return $email;
            }, $emails);

            return [
                'success' => true,
                'email' => $this->cfg['address'],
                'count' => count($emails),
                'emails' => $emails,
            ];
        } finally {
            $this->client->disconnect();
        }
    }

    /* =========================================================
       ONE EMAIL
    ========================================================= */

    /**
     * Get one complete email by UID.
     */
    public function getEmail(int $uid): array
    {
        if (! $this->can('read_emails')) {
            return $this->denied('Reading emails');
        }

        $this->connect();

        try {
            $folder = $this->client->getFolder('INBOX');

            $message = $folder
                ->messages()
                ->whereUid($uid)
                ->get()
                ->first();

            if (! $message) {
                return [
                    'success' => false,
                    'error' => 'Email not found.',
                ];
            }

            $htmlBody = (string) $message->getHTMLBody();
            $textBody = (string) $message->getTextBody();

            /*
             * Prefer plain text when available.
             */
            $body = trim(
                $textBody ?: strip_tags($htmlBody)
            );

            /*
             * Decode HTML entities.
             */
            $body = html_entity_decode(
                $body,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            /*
             * Normalize line endings, blank lines and spaces.
             */
            $body = preg_replace("/\r\n|\r/", "\n", $body);
            $body = preg_replace("/\n{3,}/", "\n\n", $body);
            $body = preg_replace("/[ \t]+/", ' ', $body);

            $body = trim($body);

            return [
                'success' => true,
                'email' => $this->cfg['address'],
                'message' => [
                    'uid' => $message->getUid(),
                    'message_id' => $message->getMessageId()?->toString(),
                    'thread_id' => $message->getReferences()?->toString(),
                    'from' => $message->getFrom()->first()?->mail,
                    'from_name' => $message->getFrom()->first()?->personal,
                    'to' => $message->getTo()->first()?->mail,
                    'to_addresses' => array_map(fn ($address): string => (string) $address->mail, $message->getTo()->all()),
                    'cc_addresses' => array_map(fn ($address): string => (string) $address->mail, $message->getCc()->all()),
                    'subject' => $message->getSubject()->toString(),
                    'date' => $message->getDate()?->toString(),
                    'seen' => $message->getFlags()->contains('Seen'),
                    'body' => $body,
                    'attachments' => $message->getAttachments()->map(fn ($attachment): array => [
                        'name' => $attachment->name ?: $attachment->filename,
                        'content_type' => $attachment->content_type,
                        'size' => $attachment->size,
                    ])->values()->all(),
                ],
            ];
        } finally {
            $this->client->disconnect();
        }
    }

    /* =========================================================
       SEARCH
    ========================================================= */

    /**
     * Search emails from the latest mailbox results.
     */
    public function findEmails(
        ?string $query = null,
        ?string $from = null,
        ?string $date = null,
        int $limit = 20,
        bool $unread = false,
    ): array {
        if (! $this->can('search_emails')) {
            return $this->denied('Searching emails');
        }

        $result = $unread ? $this->unreadEmails($limit) : $this->latestEmails($limit);

        if (! ($result['success'] ?? false)) {
            return $result;
        }

        $emails = array_filter(
            $result['emails'],
            function (array $email) use ($query, $from, $date): bool {
                /*
                 * SEARCH TEXT
                 */
                if ($query) {
                    $searchText = strtolower(
                        implode(' ', [
                            $email['subject'] ?? '',
                            $email['from'] ?? '',
                            $email['from_name'] ?? '',
                        ])
                    );

                    if (! str_contains($searchText, strtolower(trim($query)))) {
                        return false;
                    }
                }

                /*
                 * FROM FILTER
                 */
                if ($from) {
                    $emailFrom = strtolower($email['from'] ?? '');
                    $emailFromName = strtolower($email['from_name'] ?? '');
                    $fromQuery = strtolower(trim($from));

                    if (
                        ! str_contains($emailFrom, $fromQuery) &&
                        ! str_contains($emailFromName, $fromQuery)
                    ) {
                        return false;
                    }
                }

                /*
                 * DATE FILTER (expected YYYY-MM-DD)
                 */
                if ($date) {
                    $emailDate = substr((string) ($email['date'] ?? ''), 0, 10);

                    if ($emailDate !== $date) {
                        return false;
                    }
                }

                return true;
            }
        );

        $emails = array_values($emails);

        return [
            'success' => true,
            'email' => $result['email'],
            'count' => count($emails),
            'emails' => $emails,
        ];
    }

    /* =========================================================
       MARK AS READ
    ========================================================= */

    /**
     * Mark an email as read.
     */
    public function markAsRead(int $uid): array
    {
        if (! $this->can('read_emails')) {
            return $this->denied('Reading emails');
        }

        $this->connect();

        try {
            $folder = $this->client->getFolder('INBOX');

            $message = $folder
                ->messages()
                ->whereUid($uid)
                ->get()
                ->first();

            if (! $message) {
                return [
                    'success' => false,
                    'error' => 'Email not found.',
                ];
            }

            $message->setFlag('Seen');

            return [
                'success' => true,
                'uid' => $uid,
                'message' => 'Email marked as read.',
            ];
        } finally {
            $this->client->disconnect();
        }
    }

    /* =========================================================
       DELETE
    ========================================================= */

    /**
     * Delete an email.
     *
     * Permanent, so it needs its own permission: 'delete_emails'.
     * That option does not exist in the Settings form, so deletion
     * stays disabled unless you deliberately add it.
     */
    public function deleteEmail(int $uid): array
    {
        if (! $this->can('delete_emails')) {
            return $this->denied('Deleting emails');
        }

        $this->connect();

        try {
            $folder = $this->client->getFolder('INBOX');

            $message = $folder
                ->messages()
                ->whereUid($uid)
                ->get()
                ->first();

            if (! $message) {
                return [
                    'success' => false,
                    'error' => 'Email not found.',
                ];
            }

            $subject = $message->getSubject()->toString();

            $message->delete(true);

            return [
                'success' => true,
                'uid' => $uid,
                'subject' => $subject,
                'message' => 'Email deleted successfully.',
            ];
        } finally {
            $this->client->disconnect();
        }
    }

    /* =========================================================
       SEND REPLY
    ========================================================= */

    /** Send a new plain-text email through the configured SMTP mailbox. */
    public function sendEmail(string $to, string $subject, string $body): array
    {
        if (! $this->canSendNewEmails()) {
            return $this->denied('Sending new emails');
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'A valid recipient email address is required.',
            ];
        }

        if (trim($subject) === '' || preg_match('/[\r\n]/', $subject)) {
            return [
                'success' => false,
                'error' => 'A valid subject line is required.',
            ];
        }

        if (trim($body) === '') {
            return [
                'success' => false,
                'error' => 'The email message cannot be empty.',
            ];
        }

        $fromAddress = trim((string) ($this->cfg['address'] ?? ''));
        $smtpHost = trim((string) ($this->cfg['smtp_host'] ?? ''));
        $smtpUsername = trim((string) ($this->cfg['smtp_username'] ?? ''));
        $smtpPassword = (string) ($this->cfg['smtp_password'] ?? '');
        $smtpPort = (int) ($this->cfg['smtp_port'] ?? 0);

        if ($fromAddress === '' || $smtpHost === '' || $smtpUsername === '' || $smtpPassword === '' || $smtpPort < 1) {
            return [
                'success' => false,
                'error' => 'Email is not configured. Open Settings → Email.',
            ];
        }

        config([
            'mail.mailers.smtp.host' => $smtpHost,
            'mail.mailers.smtp.port' => $smtpPort,
            'mail.mailers.smtp.username' => $smtpUsername,
            'mail.mailers.smtp.password' => $smtpPassword,
            'mail.mailers.smtp.scheme' => $smtpPort === 465 ? 'smtps' : null,
            'mail.from.address' => $fromAddress,
        ]);

        Mail::purge('smtp');

        try {
            Mail::mailer('smtp')->raw($body, function ($message) use ($to, $subject, $fromAddress) {
                $message
                    ->to($to)
                    ->from($fromAddress)
                    ->subject($subject);
            });

            return [
                'success' => true,
                'message' => 'Email sent.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'error' => 'Failed to send email: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Send a reply through SMTP.
     */
    public function sendReply(
        int $uid,
        string $to,
        string $subject,
        string $body
    ): array {
        if (! $this->can('send_replies')) {
            return $this->denied('Sending replies');
        }

        if (trim($to) === '') {
            return [
                'success' => false,
                'error' => 'No recipient address provided.',
            ];
        }

        if (
            trim((string) ($this->cfg['address'] ?? '')) === '' ||
            trim((string) ($this->cfg['smtp_username'] ?? '')) === '' ||
            (string) ($this->cfg['smtp_password'] ?? '') === ''
        ) {
            return [
                'success' => false,
                'error' => 'Email is not configured. Open Settings → Email.',
            ];
        }

        /*
         * Point Laravel's SMTP mailer at the credentials from Settings.
         */
        $smtpPort = (int) $this->cfg['smtp_port'];

        config([
            'mail.mailers.smtp.host' => $this->cfg['smtp_host'],
            'mail.mailers.smtp.port' => $smtpPort,
            'mail.mailers.smtp.username' => $this->cfg['smtp_username'],
            'mail.mailers.smtp.password' => $this->cfg['smtp_password'],
            'mail.mailers.smtp.scheme' => $smtpPort === 465 ? 'smtps' : null,
            'mail.from.address' => $this->cfg['address'],
        ]);

        Mail::purge('smtp');

        $messageId = $this->getMessageId($uid);
        $fromAddress = $this->cfg['address'];

        try {
            Mail::mailer('smtp')->html($body, function ($message) use (
                $to,
                $subject,
                $messageId,
                $fromAddress
            ) {
                $message
                    ->to($to)
                    ->from($fromAddress)
                    ->subject($subject);

                if ($messageId) {
                    $message->withSymfonyMessage(
                        function (SymfonyEmail $email) use ($messageId) {
                            $email
                                ->getHeaders()
                                ->addTextHeader('In-Reply-To', $messageId)
                                ->addTextHeader('References', $messageId);
                        }
                    );
                }
            });

            return [
                'success' => true,
                'uid' => $uid,
                'message' => 'Reply sent.',
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'success' => false,
                'error' => 'Failed to send reply: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Get Message-ID for an email.
     */
    private function getMessageId(int $uid): ?string
    {
        try {
            $this->connect();

            $folder = $this->client->getFolder('INBOX');

            $message = $folder
                ->messages()
                ->whereUid($uid)
                ->get()
                ->first();

            return $message?->getMessageId()?->toString();
        } catch (Throwable) {
            return null;
        } finally {
            try {
                $this->client->disconnect();
            } catch (Throwable) {
                // Not connected.
            }
        }
    }

    /* =========================================================
       UNREAD EMAILS
    ========================================================= */

    /**
     * Get unread emails.
     */
    public function unreadEmails(int $limit = 10): array
    {
        if (! $this->can('read_emails')) {
            return $this->denied('Reading emails');
        }

        $this->connect();

        try {
            $folder = $this->client->getFolder('INBOX');

            $messages = $folder
                ->messages()
                ->unseen()
                ->setFetchOrder('desc')
                ->limit($limit)
                ->get();

            $emails = [];

            foreach ($messages as $message) {
                $date = $message->getDate();

                $timestamp = null;

                if ($date) {
                    try {
                        $timestamp = $date->getTimestamp();
                    } catch (Throwable) {
                        $timestamp = null;
                    }
                }

                $emails[] = [
                    'uid' => $message->getUid(),
                    'from' => $message->getFrom()->first()?->mail,
                    'from_name' => $message->getFrom()->first()?->personal,
                    'subject' => $message->getSubject()->toString(),
                    'date' => $date?->toString(),
                    'timestamp' => $timestamp,
                    'seen' => false,
                ];
            }

            /*
             * Newest unread email first.
             */
            usort($emails, function (array $a, array $b): int {
                $dateA = $a['timestamp'] ?? 0;
                $dateB = $b['timestamp'] ?? 0;

                if ($dateA !== $dateB) {
                    return $dateB <=> $dateA;
                }

                return ((int) ($b['uid'] ?? 0))
                    <=> ((int) ($a['uid'] ?? 0));
            });

            $emails = array_slice($emails, 0, $limit);

            $emails = array_map(function (array $email): array {
                unset($email['timestamp']);

                return $email;
            }, $emails);

            return [
                'success' => true,
                'email_account_id' => $this->account?->id,
                'count' => count($emails),
                'emails' => $emails,
            ];
        } finally {
            $this->client->disconnect();
        }
    }

    public function providerName(): string
    {
        return (string) config('email.provider', 'imap');
    }

    public function accountAddress(): string
    {
        return (string) ($this->account?->email ?? '');
    }

    /** @return EmailMessage[] */
    public function getUnreadMessages(int $limit = 15): array
    {
        $unread = $this->unreadEmails($limit);

        if (! ($unread['success'] ?? false)) {
            throw new RuntimeException($unread['error'] ?? 'Unable to retrieve unread email.');
        }

        $emails = [];

        foreach ($unread['emails'] as $header) {
            $uid = (int) ($header['uid'] ?? 0);
            $result = $this->getEmail($uid);

            if (! ($result['success'] ?? false)) {
                continue;
            }

            $emails[] = $this->normalizer->normalize(
                $result['message'],
                $this->providerName(),
                $this->accountAddress(),
                (string) $uid,
            );
        }

        return $emails;
    }

    public function getUnreadCount(): int
    {
        if (! $this->can('read_emails')) {
            throw new RuntimeException('Reading emails is turned off in Settings.');
        }

        $this->connect();

        try {
            return $this->client->getFolder('INBOX')
                ->messages()
                ->unseen()
                ->count();
        } finally {
            $this->client->disconnect();
        }
    }

    public function getMessage(string $providerMessageId): ?EmailMessage
    {
        if (! ctype_digit($providerMessageId)) {
            return null;
        }

        $result = $this->getEmail((int) $providerMessageId);

        if (! ($result['success'] ?? false)) {
            return null;
        }

        return $this->normalizer->normalize(
            $result['message'],
            $this->providerName(),
            $this->accountAddress(),
            $providerMessageId,
        );
    }

    public function markMessageAsRead(string $providerMessageId): array
    {
        if (! ctype_digit($providerMessageId)) {
            return ['success' => false, 'error' => 'The IMAP message identifier is invalid.'];
        }

        return $this->markAsRead((int) $providerMessageId);
    }
}
