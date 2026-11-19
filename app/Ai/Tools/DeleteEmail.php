<?php

namespace App\Ai\Tools;

use App\Models\PendingEmailAction;
use App\Services\GoDaddyEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DeleteEmail implements Tool
{
    public function capabilityMetadata(): array
    {
        return ['category' => 'email', 'risk' => 'DESTRUCTIVE', 'operations' => ['stage_deletion'], 'intent_verbs' => ['delete', 'remove', 'trash'], 'configuration' => 'imap',
            'permissions' => ['delete_emails'], 'requires_confirmation' => true];
    }

    public function __construct(
        private GoDaddyEmailService $emailService
    ) {}

    public function description(): Stringable|string
    {
        return 'Delete a specific email, using its uid from the email search/list results. Deletion is permanent, so this only creates a pending delete and returns a confirmation code — it never deletes immediately. Use this when the user asks to delete, remove, or trash an email.';
    }

    public function handle(Request $request): Stringable|string
    {
        $uid = $request['uid'] ?? null;

        if (! $uid) {
            return json_encode(['success' => false, 'error' => 'No uid provided.']);
        }

        $pending = PendingEmailAction::createPending(
            action: 'delete',
            emailId: (string) $uid,
            payload: [],
            expiresInMinutes: config('jarvis.pending_action_ttl_minutes', 30),
        );

        return json_encode([
            'success' => true,
            'token' => $pending->token,
            'note' => 'pending_deletion_awaiting_confirmation',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uid' => $schema
                ->integer()
                ->description('The uid of the email to delete, from the email search/list results.'),
        ];
    }
}
