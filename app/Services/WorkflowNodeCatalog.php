<?php

namespace App\Services;

class WorkflowNodeCatalog
{
    public const DEFINITIONS = [
        'email_trigger' => [
            'label' => 'Email received',
            'category' => 'trigger',
            'description' => 'Start when a new email arrives in your registered mailbox.',
            'outputs' => ['from', 'from_name', 'to', 'subject', 'date', 'body'],
        ],
        'schedule_trigger' => [
            'label' => 'Schedule',
            'category' => 'trigger',
            'description' => 'Start at a regular time.',
            'outputs' => ['scheduled_at'],
        ],
        'webhook_trigger' => [
            'label' => 'Webhook',
            'category' => 'trigger',
            'description' => 'Start when another app sends an event to JARVIS.',
            'outputs' => ['body', 'headers', 'received_at'],
        ],
        'jarvis_ai' => [
            'label' => 'JARVIS AI',
            'category' => 'action',
            'description' => 'Analyze input and return named fields for the next step.',
            'outputs' => [],
        ],
        'send_email' => [
            'label' => 'Send email',
            'category' => 'action',
            'description' => 'Send a message using your registered mailbox.',
            'outputs' => ['sent', 'to', 'subject'],
        ],
        'webhook_action' => [
            'label' => 'Webhook / Zapier',
            'category' => 'action',
            'description' => 'Send workflow data to a public HTTPS endpoint.',
            'outputs' => ['status', 'response'],
        ],
    ];

    public function all(): array
    {
        return self::DEFINITIONS;
    }

    public function has(string $type): bool
    {
        return isset(self::DEFINITIONS[$type]);
    }

    public function isTrigger(string $type): bool
    {
        return (self::DEFINITIONS[$type]['category'] ?? null) === 'trigger';
    }

    public function outputsFor(array $node): array
    {
        $type = $node['type'] ?? '';
        $outputs = self::DEFINITIONS[$type]['outputs'] ?? [];

        if ($type === 'jarvis_ai') {
            $outputs = array_values(array_filter(
                $node['config']['fields'] ?? [],
                fn ($field) => is_string($field) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/', $field),
            ));
        }

        return $outputs;
    }
}
