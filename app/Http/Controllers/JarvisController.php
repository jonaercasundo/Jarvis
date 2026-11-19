<?php

namespace App\Http\Controllers;

use App\Ai\Agents\JcAgent;
use App\Models\JarvisCreditUsage;
use App\Models\JarvisSetting;
use App\Models\PendingEmailAction;
use App\Models\User;
use App\Services\ArtifactPresentationPlanner;
use App\Services\CapabilityResponder;
use App\Services\ConversationIntentResolver;
use App\Services\ConversationResponsePresenter;
use App\Services\Email\EmailStatusService;
use App\Services\GoDaddyEmailService;
use App\Services\JarvisCreditService;
use App\Services\WorkflowAgentStatusService;
use App\Services\WorkflowOperationsService;
use App\Services\WorkflowOwner;
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
        EmailStatusService $emailStatusService,
        ConversationResponsePresenter $presenter,
        ?GoDaddyEmailService $emailService = null,
    ): JsonResponse {
        $request->validate([
            'command' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'clarification_id' => ['nullable', 'uuid'],
        ]);
        $resolver = app(ConversationIntentResolver::class);
        $resolution = $resolver->resolve($request, trim($request->input('command')));
        $request->attributes->set('jarvis_resolved_command', $resolution['command']);
        if ($resolution['response'] !== null) {
            $response = response()->json(['conversation_id' => $request->input('conversation_id'), ...$resolution['response']]);
        } else {
            $response = $this->executeCommand($request, $emailStatusService, $emailService, $resolution['command']);
        }
        if ($question = $request->attributes->get('jarvis_agent_clarification')) {
            $data = $response->getData(true);
            $response = response()->json([...array_intersect_key($data, array_flip(['conversation_id', 'credit', 'ai_usage', 'wake_name_variants'])), ...$question]);
        }
        $data = $response->getData(true);
        if (($data['type'] ?? '') === 'email_status' && ($data['success'] ?? false) && ($data['configured'] ?? false)
            && $request->attributes->get('jarvis_response_depth') === 'concise' && isset($data['analyzed_count'])) {
            $attentionCount = $data['critical_count'] + $data['action_required_count'] + $data['important_count'];
            $data['response'] = $attentionCount.' analyzed unread message'.($attentionCount === 1 ? '' : 's').' need'.($attentionCount === 1 ? 's' : '').' attention.';
            if ($data['critical_count'] > 0) {
                $data['response'] .= ' '.$data['critical_count'].' '.($data['critical_count'] === 1 ? 'is' : 'are').' urgent.';
            } elseif (($data['not_analyzed_count'] ?? 0) === 0) {
                $data['response'] .= ' Nothing appears immediately urgent.';
            }
            if (($data['not_analyzed_count'] ?? 0) > 0) {
                $data['response'] .= ' Some unread messages have not been analyzed yet.';
            }
            $response = response()->json($data, $response->getStatusCode());
        }
        if ($request->attributes->get('jarvis_email_focus') === 'needs_reply' && ($data['type'] ?? '') === 'email_status'
            && ($data['success'] ?? false) && ($data['configured'] ?? false)) {
            $data['items'] = array_values(array_filter($data['items'] ?? [], fn (array $item): bool => preg_match('/\b(?:reply|respond)\b/i', $item['action'] ?? '') === 1));
            $data['response'] = count($data['items']).' displayed message'.(count($data['items']) === 1 ? '' : 's').' may need a reply.';
            if (($data['not_analyzed_count'] ?? 0) > 0) {
                $data['response'] .= ' Some unread messages have not been analyzed yet.';
            }
            $response = response()->json($data, $response->getStatusCode());
        }
        $data = $presenter->present($response->getData(true), $request->attributes->get('jarvis_response_depth', 'concise'));
        if (($data['display']['type'] ?? '') === 'confirmation') {
            $request->session()->put('jarvis_draft_reviews.'.$data['display']['draft']['confirmation_code'], [
                'owner_key' => app(WorkflowOwner::class)->key($request), 'conversation_id' => $data['conversation_id'] ?? null,
            ]);
        }
        $response->setData($data);
        $resolver->rememberResult($request, $data);

        return $response;
    }

    private function executeCommand(
        Request $request,
        EmailStatusService $emailStatusService,
        ?GoDaddyEmailService $emailService,
        string $resolvedCommand,
    ): JsonResponse {
        $validated = $request->validate([
            'command' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
            'email_uid' => ['nullable'],
        ]);

        $command = $resolvedCommand;
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
            $capabilityResponse = $request->attributes->get('jarvis_explanation_only') ? null
                : app(CapabilityResponder::class)->respond($request, $command, $emailService);
            if ($capabilityResponse !== null) {
                return response()->json($capabilityResponse);
            }
            if (preg_match('/^cancel\s+([A-Z0-9]{6})$/i', $command, $cancel)) {
                $token = strtoupper($cancel[1]);
                $review = $request->session()->get('jarvis_draft_reviews.'.$token);
                if (! is_array($review) || ($review['owner_key'] ?? null) !== app(WorkflowOwner::class)->key($request)
                    || ($review['conversation_id'] ?? null) !== $request->input('conversation_id')) {
                    return response()->json(['success' => false, 'error' => 'No pending draft found for this conversation.'], 404);
                }
                $cancelled = PendingEmailAction::actionable()->where('token', $token)->update(['status' => 'cancelled']);
                $request->session()->forget('jarvis_draft_reviews.'.$token);

                return $cancelled ? response()->json(['success' => true, 'type' => 'confirmation', 'response' => 'Cancelled. The email has not been sent.'])
                    : response()->json(['success' => false, 'error' => 'That draft is no longer pending.'], 404);
            }
            if ($tokens = $this->extractConfirmationTokens($command)) {
                $emailService ??= app(GoDaddyEmailService::class);

                return $this->confirmPendingActions(
                    $tokens,
                    $emailService
                );
            }

            if (in_array($request->attributes->get('jarvis_email_focus'), ['important', 'important_unread', 'needs_reply'], true) || $this->isEmailStatusIntent($command)) {
                return $this->handleEmailStatusIntent($emailStatusService);
            }

            $emailService ??= app(GoDaddyEmailService::class);

            if ($this->hasEmailSearchFilters($command) && $this->isEmailIntent($command)) {
                return $this->handleFilteredEmailIntent($command, $emailService);
            }
            if ($this->isUnreadEmailIntent($command)) {
                return $this->handleUnreadEmailIntent($emailService);
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
                $conversationId,
                $request,
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

    private function isUnreadEmailIntent(string $command): bool
    {
        return preg_match('/\b(?:unread|new)\s+(?:emails?|mails?)\b/i', $command) === 1;
    }

    private function hasEmailSearchFilters(string $command): bool
    {
        return preg_match('/\b(?:from|about|today|on\s+\d{4}-\d{2}-\d{2})\b/i', $command) === 1;
    }

    private function handleFilteredEmailIntent(string $command, GoDaddyEmailService $emailService): JsonResponse
    {
        preg_match('/\bfrom\s+(.+?)(?=\s+(?:about|today|on\s+\d{4}-\d{2}-\d{2})\b|[.!?]*$)/i', $command, $sender);
        preg_match('/\babout\s+(.+?)(?=\s+(?:from|today|on\s+\d{4}-\d{2}-\d{2})\b|[.!?]*$)/i', $command, $query);
        preg_match('/\bon\s+(\d{4}-\d{2}-\d{2})\b/i', $command, $date);
        $arguments = ['query' => isset($query[1]) ? trim($query[1], ' .!?') : null,
            'from' => isset($sender[1]) ? trim($sender[1], ' .!?') : null,
            'date' => $date[1] ?? (preg_match('/\btoday\b/i', $command) ? now()->toDateString() : null), 'limit' => 20];
        if ($this->isUnreadEmailIntent($command)) {
            $arguments['unread'] = true;
        }
        $result = $emailService->findEmails(...$arguments);
        if (! ($result['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $result['error'] ?? 'I could not search your email.'], 403);
        }
        $emails = array_values($result['emails'] ?? []);

        return response()->json(['success' => true, 'type' => 'email', 'emails' => $emails,
            'response' => count($emails).' matching message'.(count($emails) === 1 ? '' : 's').' in the recent emails checked.']);
    }

    private function isEmailStatusIntent(string $command): bool
    {
        $command = trim($command);

        return preg_match('/\b(?:email|inbox|mail)\s+status\b|\bstatus\s+(?:of|for)\s+(?:my\s+)?(?:email|inbox|mail)\b/i', $command) === 1
            || preg_match('/^\s*(?:show|list|check)\b.*\b(?:important|urgent)\b.*\b(?:emails?|inbox|mail)\b/i', $command) === 1
            || preg_match('/\b(?:anything|anything important|anything urgent)\b.*\b(?:email|inbox|mail)\b|\b(?:email|inbox|mail)\b.*\b(?:important|urgent)\b/i', $command) === 1
            || preg_match('/\bdo\s+i\s+have\s+anything\s+(?:important|urgent)\b/i', $command) === 1
            || preg_match('/\bdo\s+i\s+need\s+to\s+check\s+(?:my\s+)?(?:email|inbox|mail)\b/i', $command) === 1
            || preg_match('/\bis\s+there\s+anything\s+i\s+need\s+to\s+respond\s+to\b/i', $command) === 1
            || preg_match('/\bwhat\s+needs\s+my\s+attention\b|\bwhat\s+happened\s+in\s+my\s+inbox\b/i', $command) === 1;
    }

    private function handleEmailStatusIntent(EmailStatusService $emailStatusService): JsonResponse
    {
        try {
            $status = $emailStatusService->summarize(self::DEFAULT_EMAIL_LIMIT);

            return response()->json([
                'success' => true,
                'type' => 'email_status',
                'response' => $status['summary'],
                ...$status,
            ]);
        } catch (Throwable $exception) {
            Log::warning('JC email status check failed', [
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => false,
                'type' => 'email_status',
                'error' => 'I could not analyze your inbox status right now. Please try again shortly.',
            ], 503);
        }
    }

    private function handleUnreadEmailIntent(GoDaddyEmailService $emailService): JsonResponse
    {
        if (! $emailService->isConfigured()) {
            return response()->json([
                'success' => true,
                'type' => 'email',
                'response' => 'Your email account is not set up yet. Add it in Settings under Email.',
                'emails' => [],
            ]);
        }

        $result = $emailService->unreadEmails(self::DEFAULT_EMAIL_LIMIT);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'I could not check your unread emails.',
            ], 403);
        }

        $emails = $result['emails'] ?? [];
        $count = count($emails);

        if ($count === 0) {
            return response()->json([
                'success' => true,
                'type' => 'email',
                'response' => 'You do not have any unread emails.',
                'emails' => [],
            ]);
        }

        $highlights = array_map(
            static function (array $email): string {
                $sender = $email['from_name'] ?? $email['from'] ?? 'an unknown sender';
                $subject = $email['subject'] ?? '(no subject)';

                return "From {$sender}, subject {$subject}.";
            },
            array_slice($emails, 0, 3),
        );

        $response = sprintf(
            'You have %d unread email%s. %s',
            $count,
            $count === 1 ? '' : 's',
            implode(' ', $highlights),
        );

        if ($count > count($highlights)) {
            $response .= sprintf(' And %d more.', $count - count($highlights));
        }

        return response()->json([
            'success' => true,
            'type' => 'email',
            'response' => $response,
            'emails' => $emails,
        ]);
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
        ?string $conversationId = null,
        ?Request $request = null,
    ): JsonResponse {
        $settings = JarvisSetting::current();

        $artifactPlanner = new ArtifactPresentationPlanner;
        $agent = new JcAgent(
            $emailService,
            $settings->ai_name ?: 'JC',
            $settings->learned_memory ?? '',
            agentStatusService: app(WorkflowAgentStatusService::class),
            workflowOwnerKey: $request ? app(WorkflowOwner::class)->key($request) : null,
            artifactPlanner: $artifactPlanner,
            userPrompt: $command,
            settings: $settings,
            responseDepth: $request?->attributes->get('jarvis_response_depth', 'concise') ?? 'concise',
            taskContext: $request?->attributes->get('jarvis_task_context', []) ?? [],
            explanationOnly: (bool) $request?->attributes->get('jarvis_explanation_only', false),
        );

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
                    model: config('jarvis.ai.default_model', 'claude-fable-5'),
                    timeout: 30,
                );
        } else {
            $response = $agent
                ->forUser($participant)
                ->prompt(
                    $command,
                    provider: 'anthropic',
                    model: config('jarvis.ai.default_model', 'claude-fable-5'),
                    timeout: 30,
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

        $responseText = (string) $response->text;
        $operationsResult = null;
        if (app(WorkflowOperationsService::class)->supportsRequest($command)) {
            foreach ($response->toolResults as $toolResult) {
                if ($toolResult->name === 'AskOperationsAgent') {
                    $operationsResult = is_string($toolResult->result) ? json_decode($toolResult->result, true) : $toolResult->result;
                }
            }
            if ($operationsResult === null && $artifactPlanner->retrievedData() === []
                && (app(WorkflowOperationsService::class)->isProjectStatusRequest($command)
                    || $request?->attributes->has('jarvis_operations_project'))
                && ! $request?->attributes->has('jarvis_agent_clarification')) {
                foreach ($agent->tools() as $tool) {
                    if ($tool->name() === 'AskOperationsAgent') {
                        $operationsResult = json_decode((string) $tool->handle(new \Laravel\Ai\Tools\Request(['request' => $command])), true);
                        if (($operationsResult['success'] ?? false) === true) {
                            $responseText = (string) $operationsResult['summary'];
                        }
                        break;
                    }
                }
            }
            if (is_array($operationsResult) && ($operationsResult['success'] ?? true) === false) {
                $responseText = app(WorkflowOperationsService::class)->failureMessage($operationsResult);
            }
        }

        if ($artifactPlanner->reportFailed()) {
            $responseText = app(ConversationResponsePresenter::class)->omitArtifactFailure($responseText, $command);
        }

        return response()->json([
            'success' => true,
            'type' => 'ai_response',

            'response' => $responseText,
            'artifacts' => $artifactPlanner->artifacts(),
            'artifact' => ($operationsResult['success'] ?? true) ? $artifactPlanner->visualReport() : null,
            ...$artifactPlanner->visualResponse(),

            'conversation_id' => $response->conversationId,
            'wake_name_variants' => JarvisSetting::current()->wake_name_variants ?? [],

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
