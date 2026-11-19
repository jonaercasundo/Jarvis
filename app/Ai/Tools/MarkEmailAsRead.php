<?php

namespace App\Ai\Tools;

use App\Services\GoDaddyEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class MarkEmailAsRead implements Tool
{
    public function capabilityMetadata(): array
    {
        return ['category' => 'email', 'risk' => 'WRITE', 'operations' => ['mark_as_read'], 'intent_verbs' => ['mark'], 'configuration' => 'imap',
            'permissions' => ['read_emails'], 'requires_confirmation' => false];
    }

    public function __construct(
        private GoDaddyEmailService $emailService
    ) {}

    public function description(): Stringable|string
    {
        return 'Mark a specific email as read, using its uid from the email search/list results. Use this when the user asks to mark an email as read.';
    }

    public function handle(Request $request): Stringable|string
    {
        $uid = $request['uid'] ?? null;

        if (! $uid) {
            return json_encode(['success' => false, 'error' => 'No uid provided.']);
        }

        return json_encode($this->emailService->markAsRead((int) $uid));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uid' => $schema
                ->integer()
                ->description('The uid of the email to mark as read, from the email search/list results.'),
        ];
    }
}
