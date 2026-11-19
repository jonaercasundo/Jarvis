<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WorkflowDefinition
{
    public function __construct(private WorkflowNodeCatalog $catalog) {}

    /** Validate graph structure and node settings before save or activation. */
    public function validate(array $data, bool $forActivation = false): void
    {
        $nodes = $data['nodes'] ?? null;
        $edges = $data['edges'] ?? null;

        if (! is_array($nodes) || count($nodes) > 30) {
            $this->fail('Add between one and 30 steps.');
        }

        if (! is_array($edges) || count($edges) > 60) {
            $this->fail('A workflow can have at most 60 connections.');
        }

        $byId = [];
        $triggers = [];
        $orchestrators = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                $this->fail('Each workflow step must be a valid node.');
            }

            $id = $node['id'] ?? '';
            $type = $node['type'] ?? '';

            if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $id) || isset($byId[$id])) {
                $this->fail('Each step needs a unique, valid ID.');
            }

            if (! is_string($type) || ! $this->catalog->has($type)) {
                $this->fail('This workflow contains an unsupported step.');
            }

            $position = $node['position'] ?? [];
            if (! is_array($position) || ! is_numeric($position['x'] ?? null) || ! is_numeric($position['y'] ?? null)) {
                $this->fail('Each step needs a valid canvas position.');
            }

            if (isset($node['label']) && (! is_string($node['label']) || mb_strlen($node['label']) > 80)) {
                $this->fail('Step names must be plain text under 81 characters.');
            }

            if (abs((float) $position['x']) > 10000 || abs((float) $position['y']) > 10000) {
                $this->fail('A step is positioned outside the workflow canvas.');
            }

            if (! is_array($node['config'] ?? [])) {
                $this->fail('Step configuration must be an object.');
            }

            $byId[$id] = $node;
            if ($this->catalog->isTrigger($type)) {
                $triggers[] = $id;
            }
            if ($type === 'orchestrator') {
                $orchestrators[] = $id;
            }

            $this->validateNode($node, $forActivation);
        }

        if (count($triggers) > 1) {
            $this->fail('Use one trigger at the start of each workflow.');
        }
        if (count($orchestrators) > 1) {
            $this->fail('Use one Orchestrator in each workflow.');
        }

        $incoming = array_fill_keys(array_keys($byId), 0);
        $adjacency = array_fill_keys(array_keys($byId), []);
        $edgeKeys = [];
        $edgeIds = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                $this->fail('Each connection must have a source and destination.');
            }

            $source = $edge['source'] ?? '';
            $target = $edge['target'] ?? '';

            if (! is_string($source) || ! is_string($target) || ! isset($byId[$source], $byId[$target]) || $source === $target) {
                $this->fail('A connection points to a missing or invalid step.');
            }

            if ($this->catalog->isTrigger($byId[$target]['type'])) {
                $this->fail('Trigger steps must come before action steps.');
            }

            if (isset($edge['id'])) {
                if (! is_string($edge['id']) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $edge['id']) || isset($edgeIds[$edge['id']])) {
                    $this->fail('Connections must have unique, valid IDs.');
                }
                $edgeIds[$edge['id']] = true;
            }

            $edgeType = $edge['edge_type'] ?? $this->inferEdgeType($byId[$source]['type'], $byId[$target]['type']);
            if (! is_string($edgeType) || ! in_array($edgeType, ['routing', 'agent_capability', 'data_flow', 'control_flow', 'trigger_flow', 'conditional_flow'], true)) {
                $this->fail('Choose a supported connection type.');
            }
            if (! $this->connectionTypeAllowed($edgeType, $byId[$source]['type'], $byId[$target]['type'])) {
                $this->fail('This connection type is not supported between these steps.');
            }

            $this->validateEdgeConfig($edge['config'] ?? []);

            $edgeKey = $source.'>'.$target;
            if (isset($edgeKeys[$edgeKey])) {
                $this->fail('Duplicate connections are not allowed.');
            }

            $edgeKeys[$edgeKey] = true;
            $adjacency[$source][] = $target;
            $incoming[$target]++;
        }

        $incomingCounts = $incoming;
        $queue = array_keys(array_filter($incoming, fn (int $count) => $count === 0));
        $visited = 0;

        while ($queue !== []) {
            $id = array_shift($queue);
            $visited++;

            foreach ($adjacency[$id] as $target) {
                $incoming[$target]--;
                if ($incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }

        if ($visited !== count($byId)) {
            $this->fail('Connections cannot loop back to an earlier step.');
        }

        if ($forActivation) {
            if (count($triggers) !== 1) {
                $this->fail('Add one trigger before activating this workflow.');
            }

            $trigger = $triggers[0];
            if (($incomingCounts[$trigger] ?? 0) !== 0) {
                $this->fail('The trigger must be the first step.');
            }

            $reachable = [$trigger => true];
            $pending = [$trigger];
            while ($pending !== []) {
                foreach ($adjacency[array_shift($pending)] as $target) {
                    if (! isset($reachable[$target])) {
                        $reachable[$target] = true;
                        $pending[] = $target;
                    }
                }
            }

            if (count($reachable) !== count($byId)) {
                $this->fail('Connect every step to the trigger before activating.');
            }

        }
    }

    /** @param array<string, mixed> $node */
    private function validateNode(array $node, bool $forActivation): void
    {
        $config = $node['config'] ?? [];
        $type = $node['type'];

        if ($this->containsCredentialKey($config)) {
            $this->fail('Store credentials in JARVIS Settings and reference a saved connection.');
        }

        if ($type === 'schedule_trigger') {
            if (! in_array($config['frequency'] ?? 'daily', ['hourly', 'daily', 'weekly'], true)) {
                $this->fail('Choose an hourly, daily, or weekly schedule.');
            }
            if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) ($config['time'] ?? '09:00'))) {
                $this->fail('Enter a valid schedule time.');
            }
            if (! in_array($config['day'] ?? 'monday', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
                $this->fail('Choose a valid day for the weekly schedule.');
            }
            if (! is_string($config['timezone'] ?? config('app.timezone', 'UTC')) || ! in_array($config['timezone'] ?? config('app.timezone', 'UTC'), timezone_identifiers_list(), true)) {
                $this->fail('Choose a valid time zone for the schedule.');
            }
            if (isset($config['minute']) && (! is_numeric($config['minute']) || (int) $config['minute'] < 0 || (int) $config['minute'] > 59)) {
                $this->fail('For hourly schedules, choose a minute from 0 to 59.');
            }
        }

        if ($type === 'email_trigger') {
            foreach (['from', 'subject_contains'] as $field) {
                if (isset($config[$field]) && (! is_string($config[$field]) || mb_strlen($config[$field]) > 255)) {
                    $this->fail('Email filters must be plain text under 256 characters.');
                }
            }
        }

        if ($type === 'condition') {
            $field = $config['field'] ?? '';
            $operator = $config['operator'] ?? 'not_empty';
            if (! is_string($field) || mb_strlen($field) > 120 || ($forActivation && trim($field) === '')
                || ! in_array($operator, ['exists', 'not_empty', 'equals', 'not_equals', 'greater_than'], true)) {
                $this->fail('Configure a supported condition field and comparison.');
            }
            if (in_array($operator, ['equals', 'not_equals', 'greater_than'], true)
                && (! is_scalar($config['value'] ?? null) || (string) $config['value'] === '')) {
                $this->fail('Add a comparison value for this condition.');
            }
        }

        if ($type === 'transform') {
            $mappings = $config['mappings'] ?? [];
            if (! is_array($mappings) || count($mappings) > 30) {
                $this->fail('Add no more than 30 fields to a transform.');
            }
            foreach ($mappings as $target => $source) {
                if (! is_string($target) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/', $target)
                    || ! is_string($source) || trim($source) === '' || mb_strlen($source) > 160) {
                    $this->fail('Transforms need a valid output field and source path for every mapping.');
                }
            }
            if ($forActivation && $mappings === []) {
                $this->fail('Add at least one field mapping before activating the workflow.');
            }
        }

        if ($type === 'manual_trigger' && $forActivation) {
            $this->fail('Manual test triggers cannot be activated. Add a schedule, email, or webhook trigger.');
        }

        if ($type === 'jarvis_ai') {
            $instruction = $config['instruction'] ?? '';
            if (! is_string($instruction) || ($forActivation && trim($instruction) === '') || mb_strlen($instruction) > 5000) {
                $this->fail('Add an instruction for the JARVIS AI step.');
            }

            $fields = $config['fields'] ?? [];
            if (! is_array($fields) || ($forActivation && count($fields) < 1) || count($fields) > 12) {
                $this->fail('Add between one and 12 output fields for JARVIS AI.');
            }

            foreach ($fields as $field) {
                if (! is_string($field) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/', $field)) {
                    $this->fail('Output field names can use letters, numbers, and underscores.');
                }
            }
        }

        if (in_array($type, ['ai_agent', 'orchestrator'], true)) {
            if (isset($config['model']) && (! is_string($config['model']) || strlen($config['model']) > 100)) {
                $this->fail('Choose a valid AI model or use JARVIS Default.');
            }
            if (isset($config['permissions'])) {
                if (! is_array($config['permissions']) || array_diff(array_keys($config['permissions']), array_keys(WorkflowAgentRuntime::DEFAULT_PERMISSIONS)) !== []) {
                    $this->fail('Choose supported Agent permissions.');
                }
                foreach ($config['permissions'] as $permission) {
                    if (! is_bool($permission)) {
                        $this->fail('Agent permissions must be checked or unchecked.');
                    }
                }
            }
            if (isset($config['require_approval']) && ! is_bool($config['require_approval'])) {
                $this->fail('The Agent confirmation policy must be checked or unchecked.');
            }
        }

        if ($type === 'ai_agent') {
            $executionMode = $config['execution_mode'] ?? 'ai_assisted';
            if (! in_array($executionMode, ['ai_assisted', 'deterministic'], true)) {
                $this->fail('Choose Autonomous reasoning or Fixed actions for the Agent.');
            }
            $instruction = $config['instruction'] ?? '';
            if (! is_string($instruction) || mb_strlen($instruction) > 5000 || ($forActivation && $executionMode === 'ai_assisted' && trim($instruction) === '')) {
                $this->fail('Describe what this Agent should do before activating Autonomous reasoning.');
            }
            if (isset($config['task_type']) && (! is_string($config['task_type']) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/', $config['task_type']))) {
                $this->fail('Agent task types must use letters, numbers, dashes, or underscores.');
            }
            if (isset($config['priority']) && (! is_numeric($config['priority']) || (int) $config['priority'] < 1 || (int) $config['priority'] > 100)) {
                $this->fail('Agent task priority must be between 1 and 100.');
            }
        }

        if ($type === 'send_email') {
            foreach (['to', 'subject', 'body'] as $field) {
                $value = $config[$field] ?? '';
                if (! is_string($value) || ($forActivation && trim($value) === '')) {
                    $this->fail('Complete the recipient, subject, and message in the Send email step.');
                }
            }

            if (mb_strlen((string) ($config['subject'] ?? '')) > 255 || mb_strlen((string) ($config['body'] ?? '')) > 100000) {
                $this->fail('The email subject or message is too long.');
            }
        }

        if ($type === 'webhook_action') {
            $url = $config['url'] ?? '';
            if ($url === '' && ! $forActivation) {
                return;
            }
            $parts = is_string($url) ? parse_url($url) : false;
            if (! is_string($url) || mb_strlen($url) > 2048 || ! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                $this->fail('Webhook actions need a public HTTPS URL.');
            }
            if (! in_array(strtoupper($config['method'] ?? 'POST'), ['POST', 'PUT', 'PATCH'], true)) {
                $this->fail('Webhook actions support POST, PUT, or PATCH.');
            }
        }
    }

    private function containsCredentialKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/(?:password|secret|token|api[_-]?key|credential|authorization|metabase.session)/i', $key)) {
                return true;
            }

            if (is_array($value) && $this->containsCredentialKey($value)) {
                return true;
            }
            if (is_string($value)) {
                if (preg_match('/(?:authorization|cookie|password|secret|token|api[_-]?key|metabase.session)\s*["\x27]?\s*[:=]/i', $value)) {
                    return true;
                }
                $decoded = json_decode($value, true);
                if (is_array($decoded) && $this->containsCredentialKey($decoded)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function inferEdgeType(string $sourceType, string $targetType): string
    {
        if ($sourceType === 'orchestrator' && $targetType === 'ai_agent') {
            return 'routing';
        }
        if ($sourceType === 'ai_agent' && ! $this->catalog->isTrigger($targetType)) {
            return 'agent_capability';
        }
        if ($this->catalog->isTrigger($sourceType)) {
            return 'trigger_flow';
        }

        return 'data_flow';
    }

    private function connectionTypeAllowed(string $edgeType, string $sourceType, string $targetType): bool
    {
        $sourceCategory = $this->catalog->all()[$sourceType]['category'] ?? 'action';
        $targetCategory = $this->catalog->all()[$targetType]['category'] ?? 'action';

        return match ($edgeType) {
            'routing' => $sourceType === 'orchestrator' && $targetType === 'ai_agent',
            'agent_capability' => $sourceType === 'ai_agent' && in_array($targetCategory, ['action', 'condition', 'transform', 'output'], true),
            'data_flow' => in_array($sourceCategory, ['action', 'transform'], true)
                && in_array($targetCategory, ['action', 'transform', 'output', 'condition'], true),
            'trigger_flow' => $sourceCategory === 'trigger' && $targetCategory !== 'trigger',
            'control_flow', 'conditional_flow' => $sourceCategory !== 'trigger' && $targetCategory !== 'trigger',
            default => false,
        };
    }

    /** @param array<string, mixed> $config */
    private function validateEdgeConfig(mixed $config): void
    {
        if (! is_array($config)) {
            $this->fail('Connection settings must be an object.');
        }
        if ($this->containsCredentialKey($config)) {
            $this->fail('Connection settings cannot contain credentials.');
        }

        foreach ([
            'trigger' => ['manual', 'row_created', 'row_updated', 'row_created_or_updated'],
            'mode' => ['create', 'update', 'upsert', 'skip'],
            'on_error' => ['stop_branch', 'stop_workflow', 'continue', 'retry'],
        ] as $field => $allowed) {
            if (isset($config[$field]) && (! is_string($config[$field]) || ! in_array($config[$field], $allowed, true))) {
                $this->fail('Choose a supported connection setting.');
            }
        }

        if (isset($config['branch']) && ! in_array($config['branch'], ['true', 'false', 'yes', 'no'], true)) {
            $this->fail('Condition connections must use a true or false branch.');
        }

        if (isset($config['duplicate_key']) && (! is_string($config['duplicate_key']) || mb_strlen($config['duplicate_key']) > 100)) {
            $this->fail('The duplicate key must be under 101 characters.');
        }

        $mapping = $config['mapping'] ?? [];
        if (! is_array($mapping) || count($mapping) > 30) {
            $this->fail('Add no more than 30 field mappings per connection.');
        }
        foreach ($mapping as $targetField => $sourceField) {
            if (! is_string($targetField) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_. -]{0,99}$/', $targetField)
                || ! is_string($sourceField) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_. -]{0,99}$/', $sourceField)) {
                $this->fail('Field mappings must use valid source and destination field names.');
            }
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }
}
