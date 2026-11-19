<?php

namespace App\Ai\Tools;

use App\Services\GoDaddyEmailService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FindEmails implements Tool
{
    public function capabilityMetadata(): array
    {
        return ['category' => 'email', 'risk' => 'READ_ONLY', 'operations' => ['search'], 'configuration' => 'imap',
            'permissions' => ['read_emails', 'search_emails'], 'requires_confirmation' => false];
    }

    public function __construct(
        private GoDaddyEmailService $emailService
    ) {}

    public function description(): Stringable|string
    {
        return 'Search the mailbox using text, sender, or an exact date only when the user is asking you to find, check, locate, or identify email. Do not search just because the user mentions or discusses an email.';
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = ['query' => $request['query'] ?? null, 'from' => $request['from'] ?? null,
            'date' => $request['date'] ?? null, 'limit' => $request['limit'] ?? 20];
        if ($request['unread'] ?? false) {
            $arguments['unread'] = true;
        }
        $result = $this->emailService->findEmails(...$arguments);

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'unread' => $schema->boolean()->description('Only search unread messages when requested.')->default(false),
            'query' => $schema
                ->string()
                ->description(
                    'Text to search for in the email subject, sender, or sender name. Example: PC Express or warranty.'
                )
                ->nullable(),

            'from' => $schema
                ->string()
                ->description(
                    'Optional sender email address or sender name.'
                )
                ->nullable(),

            'date' => $schema
                ->string()
                ->description(
                    'Optional exact email date in YYYY-MM-DD format.'
                )
                ->nullable(),

            'limit' => $schema
                ->integer()
                ->description(
                    'Maximum number of emails to return.'
                )
                ->min(1)
                ->max(50)
                ->default(20),
        ];
    }
}
