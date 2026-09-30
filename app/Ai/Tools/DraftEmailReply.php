<?php

namespace App\Ai\Tools;

use App\Models\PendingEmailAction;
use App\Services\GoDaddyEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DraftEmailReply implements Tool
{
    public function __construct(
        private GoDaddyEmailService $emailService
    ) {}

    public function description(): Stringable|string
    {
        return 'Prepare a reply to a specific email, using its uid from the email search/list results. This always holds the reply for user review and confirmation and never sends it directly. Use this when the user asks to reply to, respond to, or answer an email. Find the email first (to get its uid, sender address, and subject) before calling this. Present the prepared reply conversationally, without Draft/To/Subject/Body field labels or a Yes/No template. The user must type the exact confirmation phrase returned by this tool to send it.';
    }

    public function handle(Request $request): Stringable|string
    {
        $uid = $request['uid'] ?? null;
        $to = $request['to'] ?? null;
        $body = $request['body'] ?? null;

        if (! $uid || ! $to || ! $body) {
            return json_encode(['success' => false, 'error' => 'uid, to, and body are all required.']);
        }

        $subject = $request['subject'] ?? 'Re:';

        $pending = PendingEmailAction::createPending(
            action: 'reply',
            emailId: (string) $uid,
            payload: [
                'to' => $to,
                'subject' => $subject,
                'body' => $body,
            ],
            expiresInMinutes: config('jarvis.pending_action_ttl_minutes', 30),
        );

        return json_encode([
            'success' => true,
            'message' => "I have a reply ready for {$to} with the subject \"{$subject}\":\n\n{$body}\n\nIt has not been sent. If it looks good, reply \"confirm {$pending->token}\" and I will send it. You can also tell me what to change.",
            'token' => $pending->token,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uid' => $schema
                ->integer()
                ->description('The uid of the email being replied to, from the email search/list results.'),

            'to' => $schema
                ->string()
                ->description('The recipient email address — the sender of the original email.'),

            'subject' => $schema
                ->string()
                ->description('The reply subject line, typically "Re: " plus the original subject.')
                ->nullable(),

            'body' => $schema
                ->string()
                ->description('The reply text to send.'),
        ];
    }
}
