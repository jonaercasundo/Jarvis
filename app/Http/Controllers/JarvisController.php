<?php

namespace App\Http\Controllers;

use App\Ai\Agents\JcAgent;
use App\Models\JarvisCreditUsage;
use App\Models\PendingEmailAction;
use App\Models\User;
use App\Services\GoDaddyEmailService;
use App\Services\JarvisCreditService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class JarvisController extends Controller
{
    private const EMAIL_INTENT_KEYWORDS = [
        'latest emails',
        'latest email',
        'recent emails',
        'recent email',
    ];

    private const DEFAULT_EMAIL_LIMIT = 10;

    public function credits(
        JarvisCreditService $creditService
    ): JsonResponse {
        try {
            $credit = $creditService->getBalance();

            return response()->json([
                'success' => true,
                'credit' => $credit,
            ]);
        } catch (Throwable $e) {
            Log::error('JARVIS credit balance failed', [
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function email(
        $uid,
        GoDaddyEmailService $emailService
    ): JsonResponse {
        try {
            $emailService->getEmail($uid);

            if ($uid <= 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid email UID.',
                ], 422);
            }

            $result = $emailService->getEmail($uid);

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Email not found.',
                ], 404);
            }

            /*
            * GoDaddyEmailService::getEmail() returns
            * the actual message inside "message".
            */
            $email = $result['message'] ?? null;

            if (! $email) {
                return response()->json([
                    'success' => false,
                    'error' => 'Email content was not returned.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'email' => $email,
            ]);

        } catch (Throwable $e) {
            Log::error('JC email open failed', [
                'uid' => $uid,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to open the email.',
            ], 500);
        }
    }

    public function command(
        Request $request,
        GoDaddyEmailService $emailService
    ): JsonResponse {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'email_uid' => ['nullable'],
        ]);

        $command = trim($validated['command']);
        $conversationId = $validated['conversation_id'] ?? null;
        $emailUid = $validated['email_uid'] ?? null;

        if ($command === '') {
            return response()->json([
                'success' => false,
                'error' => 'Command cannot be empty.',
            ], 422);
        }

        try {

            /*
            * ---------------------------------------------------------
            * Confirmation commands
            * ---------------------------------------------------------
            *
            * Examples:
            *   "yes"
            *   "confirm"
            *   "send it"
            */
            if ($tokens = $this->extractConfirmationTokens($command)) {
                return $this->confirmPendingActions(
                    $tokens,
                    $emailService
                );
            }

            /*
            * ---------------------------------------------------------
            * Email commands
            * ---------------------------------------------------------
            *
            * Examples:
            *   "show emails from John"
            *   "show emails about warranty"
            *   "show emails from September 24"
            *   "what is my latest email?"
            *
            * Pass the actual command into the email handler so it
            * can determine sender, subject, date, etc.
            */
            if ($this->isEmailIntent($command)) {
                return $this->handleEmailIntent(
                    $command,
                    $emailService,
                    $conversationId,
                    $emailUid
                );
            }

            /*
            * ---------------------------------------------------------
            * General AI / agent command
            * ---------------------------------------------------------
            */
            return $this->handleAgentCommand(
                $command,
                $emailService,
                $conversationId
            );

        } catch (Throwable $e) {

            Log::error('JC command failed', [
                'command' => $command,
                'conversation_id' => $conversationId,
                'email_uid' => $emailUid,
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'error' => config('app.debug')
                    ? $e->getMessage().' ('.get_class($e).')'
                    : 'Something went wrong while processing that command.',
            ], 500);
        }
    }

    /**
     * Matches:
     *   "confirm ABC123"
     *   "confirm ABC123 XYZ789" / "confirm ABC123, XYZ789, DEF456"
     *   "confirm all"
     * so a reply/delete confirmation never has to round-trip through the
     * AI agent. Returns null (not "all") to signal "confirm every pending
     * action" to the caller.
     *
     * @return string[]|null
     */
    private function extractConfirmationTokens(string $command): ?array
    {
        if (! preg_match('/^confirm\s+(.+)$/i', trim($command), $matches)) {
            return null;
        }

        $rest = trim($matches[1]);

        if (strcasecmp($rest, 'all') === 0) {
            return ['*'];
        }

        $tokens = preg_split('/[\s,]+/', $rest, -1, PREG_SPLIT_NO_EMPTY);

        if (empty($tokens)) {
            return null;
        }

        return array_map('strtoupper', $tokens);
    }

    private function confirmPendingActions(array $tokens, GoDaddyEmailService $emailService): JsonResponse
    {
        $pendingActions = $tokens === ['*']
            ? PendingEmailAction::actionable()->get()
            : PendingEmailAction::actionable()->whereIn('token', $tokens)->get();

        if ($pendingActions->isEmpty()) {
            return response()->json([
                'success' => false,
                'error' => $tokens === ['*']
                    ? 'No pending actions to confirm.'
                    : 'No pending action found for that code (it may have expired or already run).',
            ], 404);
        }

        $results = [];

        foreach ($pendingActions as $pending) {
            $results[] = $this->executePendingAction($pending, $emailService);
        }

        $successCount = count(array_filter($results, fn ($r) => $r['success']));

        return response()->json([
            'success' => $successCount === count($results),
            'type' => 'confirmation',
            'response' => $this->summarizeConfirmations($results),
        ]);
    }

    private function executePendingAction(PendingEmailAction $pending, GoDaddyEmailService $emailService): array
    {
        $uid = (int) $pending->email_id;

        $result = match ($pending->action) {
            'reply' => $emailService->sendReply(
                $uid,
                $pending->payload['to'] ?? '',
                $pending->payload['subject'] ?? '',
                $pending->payload['body'] ?? '',
            ),
            'send' => $emailService->sendEmail(
                $pending->payload['to'] ?? '',
                $pending->payload['subject'] ?? '',
                $pending->payload['body'] ?? '',
            ),
            'delete' => $emailService->deleteEmail($uid),
            default => ['success' => false, 'error' => "Unknown action: {$pending->action}"],
        };

        $success = (bool) ($result['success'] ?? false);

        $pending->update(['status' => $success ? 'completed' : 'failed']);

        return [
            'token' => $pending->token,
            'action' => $pending->action,
            'success' => $success,
            'detail' => $result['message'] ?? $result['error'] ?? null,
        ];
    }

    private function summarizeConfirmations(array $results): string
    {
        if (count($results) === 1) {
            $r = $results[0];

            return $r['detail'] ?? ($r['success']
                ? ucfirst($r['action']).' completed.'
                : "Failed to complete the {$r['action']}.");
        }

        $succeeded = array_filter($results, fn ($r) => $r['success']);
        $failed = array_filter($results, fn ($r) => ! $r['success']);

        $lines = [sprintf('%d of %d confirmed.', count($succeeded), count($results))];

        foreach ($failed as $r) {
            $lines[] = "{$r['token']} failed".($r['detail'] ? ": {$r['detail']}" : '.');
        }

        return implode("\n", $lines);
    }

    private function isEmailIntent(string $command): bool
    {
        $command = trim($command);

        $explicitSearchRequest = preg_match(
            '/^(?:(?:please|can you|could you|would you)\s+)*(?:find|search(?:\s+for)?|look\s+for|show|list|check)\b.*\b(?:emails?|mails?)\b/i',
            $command,
        ) === 1;

        if ($explicitSearchRequest) {
            return true;
        }

        $latestEmailQuestion = preg_match(
            '/^(?:what(?:\'s|\s+is|\s+was|\s+are|\s+were)?|who\s+sent|when\s+did\s+i\s+get)\b.*\b(?:my|the)\b.*\b(?:latest|recent|new)?\s*emails?\b/i',
            $command,
        ) === 1;

        $newEmailQuestion = preg_match(
            '/^(?:do\s+i\s+have|did\s+i\s+get|have\s+i\s+received)\b.*\b(?:any\s+)?(?:new|latest|recent)?\s*emails?\b/i',
            $command,
        ) === 1;

        return $latestEmailQuestion || $newEmailQuestion;
    }

    private function handleEmailIntent(
        string $command,
        GoDaddyEmailService $emailService,
        ?string $conversationId = null,
        $emailUid = null
    ): JsonResponse {

        /*
        * ---------------------------------------------------------
        * Get the requested emails.
        * ---------------------------------------------------------
        */

        $result = $emailService->latestEmails(
            self::DEFAULT_EMAIL_LIMIT
        );

        $emails = $result['emails'] ?? [];

        Log::info('JC email search result', [
            'emails' => $emails,
        ]);

        /*
        * Make sure we always have an array.
        */
        if (! is_array($emails)) {
            $emails = [];
        }

        /*
        * ---------------------------------------------------------
        * No emails
        * ---------------------------------------------------------
        */

        if (empty($emails)) {
            return response()->json([
                'success' => true,
                'type' => 'email',
                'response' => 'I checked your mailbox, but I could not find any emails.',
                'emails' => [],
            ]);
        }

        /*
        * ---------------------------------------------------------
        * Normalize command
        * ---------------------------------------------------------
        */

        $normalized = strtolower(trim($command));

        /*
        * ---------------------------------------------------------
        * Determine whether this is a "latest email" request.
        * ---------------------------------------------------------
        */

        $isLatestRequest =
            str_contains($normalized, 'latest email') ||
            str_contains($normalized, 'latest emails') ||
            str_contains($normalized, 'recent email') ||
            str_contains($normalized, 'recent emails') ||
            str_contains($normalized, 'what is my latest email') ||
            str_contains($normalized, 'what was my latest email');

        /*
        * ---------------------------------------------------------
        * Latest email
        * ---------------------------------------------------------
        */

        if ($isLatestRequest) {

            /*
            * Find latest email by date.
            */
            $latest = collect($emails)
                ->sortByDesc(function (array $email) {
                    try {
                        return Carbon::parse(
                            $email['date'] ?? ''
                        )->timestamp;
                    } catch (Throwable) {
                        return 0;
                    }
                })
                ->first();

            /*
            * Check whether one exists.
            */
            if (! $latest) {
                return response()->json([
                    'success' => true,
                    'type' => 'email',
                    'response' => 'You have no emails.',
                    'emails' => [],
                ]);
            }

            /*
            * Save the latest email UID for conversation context.
            */
            if ($conversationId && ! empty($latest['uid'])) {
                Cache::put(
                    "jarvis.conversation.{$conversationId}.last_email_uid",
                    (int) $latest['uid'],
                    now()->addHours(24)
                );
            }

            /*
            * Get sender and subject.
            */
            $sender =
                $latest['from_name']
                ?? $latest['from']
                ?? 'Unknown sender';

            $subject =
                $latest['subject']
                ?? '(No Subject)';

            /*
            * Get date.
            */
            $date = $latest['date'] ?? null;

            $formattedDate = $date;

            if ($date) {
                try {
                    $formattedDate = Carbon::parse($date)
                        ->format('F j, Y g:i A');
                } catch (Throwable) {
                    $formattedDate = $date;
                }
            }

            /*
            * Build natural response.
            */
            $response =
                "Your latest email is from {$sender}, ".
                "regarding {$subject}";

            if ($formattedDate) {
                $response .= ", received on {$formattedDate}";
            }

            $response .= '.';

            /*
            * Return latest email.
            */
            return response()->json([
                'success' => true,
                'type' => 'email',
                'response' => $response,
                'emails' => [$latest],
            ]);
        }

        /*
        * ---------------------------------------------------------
        * Email list/search
        * ---------------------------------------------------------
        *
        * Examples:
        *
        * "Find emails"
        * "Show emails"
        * "Find emails from John"
        * "Show emails about invoice"
        *
        * For now, latestEmails() provides the mailbox results.
        */

        $count = count($emails);

        $response =
            "Done. I found {$count} email".
            ($count === 1 ? '' : 's').
            '.';

        /*
        * Return email list.
        */
        return response()->json([
            'success' => true,
            'type' => 'email',
            'response' => $response,
            'emails' => array_values($emails),
        ]);
    }

    private function handleAgentCommand(
        string $command,
        GoDaddyEmailService $emailService,
        ?string $conversationId = null
    ): JsonResponse {
        $agent = new JcAgent($emailService);

        // JARVIS uses a dedicated internal participant.
        // This is not a login/authentication system.
        $participant = User::firstOrCreate(
            ['email' => 'jc@localhost'],
            [
                'name' => 'JC',
                'password' => 'jc-internal',
            ]
        );

        if ($conversationId) {
            $response = $agent
                ->continueOrStart(
                    $conversationId,
                    as: $participant
                )
                ->prompt(
                    $command,
                    provider: 'anthropic',
                    model: 'claude-sonnet-5',
                );
        } else {
            $response = $agent
                ->forUser($participant)
                ->prompt(
                    $command,
                    provider: 'anthropic',
                    model: 'claude-sonnet-5',
                );
        }

        /*
        * Laravel AI SDK provides the actual provider token usage.
        *
        * Actual tokens = input + output tokens
        * Dashboard credits = actual tokens × 2
        */
        $inputTokens = (int) $response->usage->inputTokens;
        $outputTokens = (int) $response->usage->outputTokens;
        $actualTokens = $inputTokens + $outputTokens;

        $credits = $actualTokens * 2;

        JarvisCreditUsage::create([
            'conversation_id' => $response->conversationId ?? $conversationId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $actualTokens,
            'credits' => $credits,
        ]);

        $creditLimit = 10000;

        $usedCredits = JarvisCreditUsage::sum('credits');

        $balanceCredits = max(
            0,
            $creditLimit - $usedCredits
        );

        return response()->json([
            'success' => true,
            'type' => 'ai_response',

            'response' => (string) $response->text,

            'conversation_id' => $response->conversationId,

            'credit' => [
                'limit' => $creditLimit,
                'used' => $usedCredits,
                'balance' => $balanceCredits,
            ],

            'ai_usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'total_tokens' => $actualTokens,
                'credits_used' => $credits,
            ],
        ]);
    }
}
