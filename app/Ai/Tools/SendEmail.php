<?php

namespace App\Ai\Tools;

use App\Models\PendingEmailAction;
use App\Services\GoDaddyEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SendEmail implements Tool
{
    public function __construct(
        private GoDaddyEmailService $emailService
    ) {}

    public function description(): Stringable|string
    {
        return 'Prepare a new email when the user explicitly asks to write or send one. Collect its recipient, subject, and message body, then stage it for review. This tool never sends directly. If new email sending is disabled in Settings, tell the user to enable it there. Present the message conversationally, without Draft/To/Subject/Body field labels or a Yes/No template, and include the exact confirmation code returned by the tool. Do not claim it was sent until that code is confirmed and the send succeeds.';
    }

    public function handle(Request $request): Stringable|string
    {
        $to = trim((string) ($request['to'] ?? ''));
        $subject = trim((string) ($request['subject'] ?? ''));
        $body = trim((string) ($request['body'] ?? ''));

        if (! $this->emailService->canSendNewEmails()) {
            return json_encode([
                'success' => false,
                'error' => 'Sending new emails is turned off in Settings. Enable "Send new emails" under Scope to use this feature.',
            ]);
        }

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return json_encode(['success' => false, 'error' => 'A valid recipient email address is required.']);
        }

        if ($subject === '' || mb_strlen($subject) > 255 || preg_match('/[\r\n]/', $subject)) {
            return json_encode(['success' => false, 'error' => 'A valid subject line is required (up to 255 characters).']);
        }

        if ($body === '' || mb_strlen($body) > 100000) {
            return json_encode(['success' => false, 'error' => 'A message body is required (up to 100,000 characters).']);
        }

        $pending = PendingEmailAction::createPending(
            action: 'send',
            emailId: '0',
            payload: [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ],
            expiresInMinutes: config('jarvis.pending_action_ttl_minutes', 30),
        );

        return json_encode([
            'success' => true,
            'message' => "I've prepared an email for {$to} with the subject \"{$subject}\". It says:\n\n{$body}\n\nIt has not been sent. If it looks right, reply \"confirm {$pending->token}\" and I will send it. You can also tell me what to change.",
            'token' => $pending->token,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'to' => $schema
                ->string()
                ->description('The recipient email address explicitly provided or confirmed by the user.'),

            'subject' => $schema
                ->string()
                ->description('The email subject line.'),

            'body' => $schema
                ->string()
                ->description('The email message body, written in a natural tone appropriate to the user request.'),
        ];
    }
}
