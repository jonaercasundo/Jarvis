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

            $this->validateNode($node, $forActivation);
        }

        if (count($triggers) > 1) {
            $this->fail('Use one trigger at the start of each workflow.');
        }

        $incoming = array_fill_keys(array_keys($byId), 0);
        $adjacency = array_fill_keys(array_keys($byId), []);
        $edgeKeys = [];

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

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }
}
