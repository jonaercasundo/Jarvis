<?php

namespace App\Services;

use App\Models\JarvisWorkflow;
use RuntimeException;
use Throwable;

class WorkflowExecutor
{
    public function __construct(
        private WorkflowDefinition $definition,
        private WorkflowNodeCatalog $catalog,
        private WorkflowAiService $ai,
        private GoDaddyEmailService $emailService,
        private WorkflowHttpService $http,
        private JarvisWorkflowService $workflows,
    ) {}

    /** Execute a graph and persist step-by-step results. External effects are skipped in dry-run mode. */
    public function run(
        JarvisWorkflow $workflow,
        array $triggerPayload,
        string $triggerType,
        bool $dryRun = false,
    ): array {
        $data = $workflow->workflow_data ?? ['nodes' => [], 'edges' => []];
        $this->definition->validate($data, forActivation: true);

        $execution = $workflow->executions()->create([
            'trigger_type' => $triggerType,
            'status' => 'running',
            'started_at' => now(),
            'input_data' => $this->limitInput($triggerPayload),
        ]);

        $nodes = collect($data['nodes'])->keyBy('id')->all();
        $orderedIds = $this->topologicalOrder($nodes, $data['edges'] ?? []);
        $outputs = ['trigger' => $triggerPayload];
        $results = [];

        try {
            foreach ($orderedIds as $nodeId) {
                $node = $nodes[$nodeId];
                $startedAt = microtime(true);

                try {
                    $output = $this->executeNode(
                        $node,
                        $this->resolveMappings($node['config'] ?? [], $outputs),
                        $outputs,
                        $triggerPayload,
                        $triggerType,
                        $data['edges'] ?? [],
                        $dryRun,
                    );

                    if (($output['success'] ?? true) === false) {
                        throw new RuntimeException($output['error'] ?? 'This step did not complete.');
                    }

                    $outputs[$nodeId] = $output;
                    $results[] = [
                        'node_id' => $nodeId,
                        'label' => $this->label($node),
                        'status' => $dryRun && ($output['simulated'] ?? false) ? 'simulated' : 'success',
                        'output' => $this->limitOutput($output),
                        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ];
                } catch (Throwable $e) {
                    $results[] = [
                        'node_id' => $nodeId,
                        'label' => $this->label($node),
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    ];

                    throw new RuntimeException($this->label($node).': '.$e->getMessage(), previous: $e);
                }
            }

            $execution->update([
                'status' => 'success',
                'finished_at' => now(),
                'output_data' => ['nodes' => $results, 'final' => end($outputs)],
            ]);

            if (! $dryRun) {
                $workflow->update(['last_run_at' => now()]);
            }

            return [
                'success' => true,
                'execution' => $execution->fresh(),
                'results' => $results,
            ];
        } catch (Throwable $e) {
            $execution->update([
                'status' => 'failed',
                'finished_at' => now(),
                'output_data' => ['nodes' => $results],
                'error_message' => $e->getMessage(),
            ]);

            if (! $dryRun) {
                $workflow->update([
                    'status' => 'error',
                    'last_run_at' => now(),
                    'next_run_at' => null,
                ]);
            }

            return [
                'success' => false,
                'execution' => $execution->fresh(),
                'results' => $results,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function executeNode(
        array $node,
        array $config,
        array $outputs,
        array $triggerPayload,
        string $triggerType,
        array $edges,
        bool $dryRun,
    ): array {
        $type = $node['type'];

        if ($this->catalog->isTrigger($type)) {
            $expectedTrigger = match ($type) {
                'email_trigger' => 'email',
                'schedule_trigger' => 'schedule',
                'webhook_trigger' => 'webhook',
            };

            if (! $dryRun && $triggerType !== $expectedTrigger) {
                throw new RuntimeException('This workflow was started by the wrong trigger.');
            }

            if ($type === 'schedule_trigger') {
                return ['scheduled_at' => $triggerPayload['scheduled_at'] ?? now()->toIso8601String(), 'body' => $triggerPayload['body'] ?? null];
            }

            return $triggerPayload;
        }

        $source = $this->previousOutput($node['id'], $edges, $outputs, $triggerPayload);

        return match ($type) {
            'jarvis_ai' => $this->ai->analyze(
                (string) ($config['instruction'] ?? ''),
                $this->inputForAi($config, $source),
                $node['config']['fields'] ?? [],
            ),
            'send_email' => $this->sendEmail($config, $dryRun),
            'webhook_action' => $this->sendWebhook($config, $source, $dryRun),
            default => throw new RuntimeException('This step is not available yet.'),
        };
    }

    private function sendEmail(array $config, bool $dryRun): array
    {
        $to = trim((string) ($config['to'] ?? ''));
        $subject = trim((string) ($config['subject'] ?? ''));
        $body = (string) ($config['body'] ?? '');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The mapped recipient is not a valid email address.');
        }

        if (! $this->emailService->canSendNewEmails()) {
            throw new RuntimeException('Enable “Send new emails” in JARVIS Settings → Scope.');
        }

        if ($dryRun) {
            return ['simulated' => true, 'to' => $to, 'subject' => $subject, 'message' => 'Test only. No email was sent.'];
        }

        $result = $this->emailService->sendEmail($to, $subject, $body);
        if (! ($result['success'] ?? false)) {
            throw new RuntimeException($result['error'] ?? 'JARVIS could not send the email.');
        }

        return ['sent' => true, 'to' => $to, 'subject' => $subject];
    }

    private function sendWebhook(array $config, array $source, bool $dryRun): array
    {
        $payload = $config['payload'] ?? null;
        if ($payload === null || $payload === '') {
            $payload = $source;
        } elseif (! is_array($payload)) {
            $decoded = is_string($payload) ? json_decode($payload, true) : null;
            $payload = is_array($decoded) ? $decoded : ['value' => $payload];
        }

        if ($dryRun) {
            return ['simulated' => true, 'url' => $config['url'], 'method' => $config['method'] ?? 'POST', 'message' => 'Test only. No request was sent.'];
        }

        return $this->http->send(
            (string) $config['url'],
            (string) ($config['method'] ?? 'POST'),
            $payload,
        );
    }

    private function inputForAi(array $config, mixed $previousOutput): array
    {
        $input = $config['input'] ?? $previousOutput;

        if (is_array($input)) {
            return $input;
        }

        return ['text' => $input];
    }

    private function previousOutput(string $nodeId, array $edges, array $outputs, array $triggerPayload): array
    {
        $sources = array_values(array_map(
            fn (array $edge) => $edge['source'],
            array_filter($edges, fn (array $edge) => $edge['target'] === $nodeId),
        ));

        if ($sources === []) {
            return $triggerPayload;
        }

        $previous = [];
        foreach ($sources as $source) {
            $previous[$source] = $outputs[$source] ?? [];
        }

        return count($previous) === 1 ? reset($previous) : $previous;
    }

    private function resolveMappings(mixed $value, array $context): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->resolveMappings($item, $context);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $pattern = '/\{\{\s*([a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*)\s*\}\}/';
        if (preg_match($pattern, $value, $match) && $match[0] === $value) {
            $mapped = data_get($context, $match[1]);
            if ($mapped === null) {
                throw new RuntimeException('A mapped value is missing: {{'.$match[1].'}}.');
            }

            return $mapped;
        }

        return preg_replace_callback($pattern, function (array $match) use ($context) {
            $mapped = data_get($context, $match[1]);
            if ($mapped === null) {
                throw new RuntimeException('A mapped value is missing: {{'.$match[1].'}}.');
            }

            return is_scalar($mapped) ? (string) $mapped : json_encode($mapped, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $value);
    }

    private function topologicalOrder(array $nodes, array $edges): array
    {
        $incoming = array_fill_keys(array_keys($nodes), 0);
        $adjacency = array_fill_keys(array_keys($nodes), []);

        foreach ($edges as $edge) {
            $adjacency[$edge['source']][] = $edge['target'];
            $incoming[$edge['target']]++;
        }

        $queue = array_keys(array_filter($incoming, fn (int $count) => $count === 0));
        $ordered = [];
        while ($queue !== []) {
            $id = array_shift($queue);
            $ordered[] = $id;
            foreach ($adjacency[$id] as $target) {
                if (--$incoming[$target] === 0) {
                    $queue[] = $target;
                }
            }
        }

        return $ordered;
    }

    private function label(array $node): string
    {
        $title = $this->catalog->all()[$node['type']]['label'] ?? 'Step';

        return (string) ($node['label'] ?? $title);
    }

    private function limitInput(array $input): array
    {
        $json = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > 100000) {
            return ['truncated' => true, 'preview' => mb_substr($json, 0, 100000)];
        }

        return $input;
    }

    private function limitOutput(array $output): array
    {
        $json = json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && strlen($json) > 50000) {
            return ['truncated' => true, 'preview' => mb_substr($json, 0, 50000)];
        }

        return $output;
    }
}
