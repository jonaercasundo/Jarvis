<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class WorkflowGraph
{
    /** @var array<string, array<string, mixed>> */
    private array $nodesById;

    /** @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $data */
    public function __construct(private array $data)
    {
        $this->nodesById = collect($data['nodes'] ?? [])->keyBy('id')->all();
    }

    /** @return array<string, mixed>|null */
    public function node(string $nodeId): ?array
    {
        return $this->nodesById[$nodeId] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function connectedNodes(string $nodeId, string $direction = 'outgoing'): array
    {
        $field = $direction === 'incoming' ? 'target' : 'source';
        $nodeField = $direction === 'incoming' ? 'source' : 'target';

        return collect($this->data['edges'] ?? [])
            ->filter(fn (array $edge): bool => ($edge[$field] ?? null) === $nodeId)
            ->map(fn (array $edge): ?array => $this->node((string) ($edge[$nodeField] ?? '')))
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function connectedAgents(string $orchestratorId): array
    {
        return array_values(array_filter(
            $this->connectedNodes($orchestratorId),
            fn (array $node): bool => ($node['type'] ?? null) === 'ai_agent',
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function connectedApps(string $agentId): array
    {
        return array_values(array_filter(
            $this->connectedNodes($agentId),
            fn (array $node): bool => ($node['type'] ?? null) === 'app_action',
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function ownedNodes(string $agentId): array
    {
        $ownedIds = [];
        $pending = [];

        foreach ($this->data['edges'] ?? [] as $edge) {
            if (($edge['source'] ?? null) !== $agentId) {
                continue;
            }

            $targetId = (string) ($edge['target'] ?? '');
            $target = $this->node($targetId);
            if (($target['type'] ?? null) === 'app_action' && in_array($edge['edge_type'] ?? 'agent_capability', ['agent_capability', 'capability'], true)) {
                $ownedIds[$targetId] = true;
                $pending[] = $targetId;
            }
        }

        while ($pending !== []) {
            $sourceId = array_shift($pending);

            foreach ($this->data['edges'] ?? [] as $edge) {
                if (($edge['source'] ?? null) !== $sourceId || ! in_array($edge['edge_type'] ?? 'data_flow', ['data_flow', 'control_flow'], true)) {
                    continue;
                }

                $targetId = (string) ($edge['target'] ?? '');
                $target = $this->node($targetId);
                if (! $target || ($target['type'] ?? null) === 'ai_agent' || isset($ownedIds[$targetId])) {
                    continue;
                }

                $ownedIds[$targetId] = true;
                $pending[] = $targetId;
            }
        }

        return array_values(array_filter(
            array_map(fn (string $nodeId): ?array => $this->node($nodeId), array_keys($ownedIds)),
        ));
    }

    /** @return array<string, mixed> */
    public function orchestrator(): array
    {
        $orchestrators = array_values(array_filter(
            $this->nodesById,
            fn (array $node): bool => ($node['type'] ?? null) === 'orchestrator',
        ));

        if (count($orchestrators) !== 1) {
            throw ValidationException::withMessages(['workflow' => 'A test workflow must contain exactly one Orchestrator.']);
        }

        return $orchestrators[0];
    }
}
