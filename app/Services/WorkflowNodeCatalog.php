<?php

namespace App\Services;

class WorkflowNodeCatalog
{
    public const DEFINITIONS = [

        /*
        |--------------------------------------------------------------------------
        | Triggers
        |--------------------------------------------------------------------------
        */

        'email_trigger' => [
            'label' => 'Email received',
            'category' => 'trigger',
            'description' => 'Start when a new email arrives in your registered mailbox.',
            'outputs' => [
                'from',
                'from_name',
                'to',
                'subject',
                'date',
                'body',
            ],
        ],

        'schedule_trigger' => [
            'label' => 'Schedule',
            'category' => 'trigger',
            'description' => 'Start at a regular time.',
            'outputs' => [
                'scheduled_at',
            ],
        ],

        'webhook_trigger' => [
            'label' => 'Webhook',
            'category' => 'trigger',
            'description' => 'Start when another app sends an event to JARVIS.',
            'outputs' => [
                'body',
                'headers',
                'received_at',
            ],
        ],

        'manual_trigger' => [
            'label' => 'Manual test trigger',
            'category' => 'trigger',
            'description' => 'Start a safe test run with sample data.',
            'outputs' => ['body', 'project_code', 'item_code', 'quantity'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Agentic Nodes
        |--------------------------------------------------------------------------
        */

        'orchestrator' => [
            'label' => 'Orchestrator',
            'category' => 'agent',
            'description' => 'Analyze the request and route work to the appropriate specialist agent.',
            'outputs' => [
                'decision',
                'selected_agent',
                'context',
                'status',
            ],
        ],

        'ai_agent' => [
            'label' => 'AI Agent',
            'category' => 'agent',
            'description' => 'Execute a specialized task using instructions, tools, and configured output fields.',
            'outputs' => [],
        ],

        /*
        |--------------------------------------------------------------------------
        | Application Actions
        |--------------------------------------------------------------------------
        */

        'app_action' => [
            'label' => 'App Action',
            'category' => 'action',
            'description' => 'Perform an action using a connected application.',
            'outputs' => [
                'success',
                'status',
                'result',
            ],
        ],

        'condition' => [
            'label' => 'Condition',
            'category' => 'condition',
            'description' => 'Choose which connected branch runs based on input data.',
            'outputs' => ['matched', 'value'],
        ],

        'transform' => [
            'label' => 'Transform',
            'category' => 'transform',
            'description' => 'Map input fields into a new data shape.',
            'outputs' => [],
        ],

        'output' => [
            'label' => 'Output',
            'category' => 'output',
            'description' => 'Record the result of the workflow branch.',
            'outputs' => ['status', 'result'],
        ],

        /*
        |--------------------------------------------------------------------------
        | Legacy / General AI
        |--------------------------------------------------------------------------
        */

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
            'outputs' => [
                'sent',
                'to',
                'subject',
            ],
        ],

        'webhook_action' => [
            'label' => 'Webhook / Zapier',
            'category' => 'action',
            'description' => 'Send workflow data to a public HTTPS endpoint.',
            'outputs' => [
                'status',
                'response',
            ],
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

        if ($type === 'jarvis_ai' || $type === 'ai_agent') {
            $outputs = array_values(array_filter(
                $node['config']['fields'] ?? [],
                fn ($field) => is_string($field)
                    && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/', $field),
            ));
        }

        return $outputs;
    }
}
