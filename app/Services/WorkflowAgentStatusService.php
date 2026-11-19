<?php

namespace App\Services;

use App\Models\JarvisWorkflow;
use App\Models\WorkflowAgentTask;
use Illuminate\Support\Str;

class WorkflowAgentStatusService
{
    /** @return array<int, array<string, mixed>> */
    public function summaries(string $ownerKey, ?string $query = null): array
    {
        $workflows = JarvisWorkflow::query()
            ->where('owner_key', $ownerKey)
            ->orderBy('name')
            ->get();

        if ($workflows->isEmpty()) {
            return [];
        }

        $workflowIds = $workflows->pluck('id');
        $tasksByAgent = WorkflowAgentTask::query()
            ->whereIn('workflow_id', $workflowIds)
            ->latest('created_at')
            ->get()
            ->groupBy(fn (WorkflowAgentTask $task): string => $task->workflow_id.':'.$task->agent_node_id);
        $todayStart = now()->startOfDay();
        $summaries = [];

        foreach ($workflows as $workflow) {
            $graph = new WorkflowGraph($workflow->workflow_data ?? []);
            foreach ($workflow->workflow_data['nodes'] ?? [] as $agent) {
                if (($agent['type'] ?? null) !== 'ai_agent') {
                    continue;
                }

                $nodeId = (string) ($agent['id'] ?? '');
                $tasks = $tasksByAgent->get($workflow->id.':'.$nodeId, collect());
                $todayTasks = $tasks->filter(fn (WorkflowAgentTask $task): bool => $task->created_at?->greaterThanOrEqualTo($todayStart) ?? false);
                $latest = $tasks->first();
                $failedToday = $todayTasks->whereIn('status', ['failed', 'needs_attention'])->count();
                $status = $this->status($workflow->status, $latest?->status, $failedToday > 0);
                $config = $agent['config'] ?? [];
                $specialization = (string) ($config['custom_specialization'] ?? $config['specialization'] ?? 'custom');
                $label = (string) ($agent['label'] ?? Str::headline($specialization).' agent');
                $searchText = Str::lower($label.' '.$specialization.' '.($config['handles'] ?? ''));

                if ($query !== null && ! str_contains($searchText, Str::lower($query))) {
                    continue;
                }

                $summaries[] = [
                    'id' => $workflow->id.':'.$nodeId,
                    'agent_node_id' => $nodeId,
                    'workflow_id' => $workflow->id,
                    'workflow' => $workflow->name,
                    'label' => $label,
                    'specialization' => $specialization,
                    'handles' => $config['handles'] ?? '',
                    'status' => $status,
                    'today' => [
                        'processed' => $todayTasks->whereIn('status', ['completed', 'failed', 'needs_attention', 'cancelled'])->count(),
                        'successful' => $todayTasks->where('status', 'completed')->count(),
                        'failed' => $failedToday,
                    ],
                    'current_task' => in_array($latest?->status, ['queued', 'running', 'waiting'], true)
                        ? ['type' => $latest->task_type, 'status' => $latest->status, 'started_at' => $latest->started_at?->toIso8601String()]
                        : null,
                    'last_error' => $latest?->error_message,
                    'tools' => collect($graph->ownedNodes($nodeId))
                        ->map(fn (array $node): string => (string) ($node['label'] ?? $node['type'] ?? 'Step'))
                        ->values()
                        ->all(),
                ];
            }
        }

        return $summaries;
    }

    private function status(string $workflowStatus, ?string $taskStatus, bool $hasFailureToday): string
    {
        if ($taskStatus === 'running') {
            return 'working';
        }
        if ($taskStatus === 'waiting') {
            return 'waiting';
        }
        if ($hasFailureToday || in_array($taskStatus, ['failed', 'needs_attention'], true)) {
            return 'needs_attention';
        }
        if ($workflowStatus === 'error') {
            return 'error';
        }
        if ($workflowStatus !== 'active') {
            return 'offline';
        }

        return 'idle';
    }
}
