<?php

namespace App\Services;

use App\Models\JarvisWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

class WorkflowAgentDelegation
{
    public function __construct(private WorkflowExecutor $executor, private WorkflowAgentTools $tools) {}

    /** Public descriptions for the central orchestrator; never connection values or credentials. */
    public function available(string $ownerKey): array
    {
        $available = [];
        foreach ($this->workflows($ownerKey) as $workflow) {
            $graph = new WorkflowGraph($workflow->workflow_data ?? []);
            try {
                $router = $graph->orchestrator();
            } catch (Throwable) {
                continue;
            }
            $agents = $graph->connectedAgents($router['id']);
            if (! collect($agents)->contains(fn (array $agent): bool => ($agent['config']['execution_mode'] ?? 'ai_assisted') === 'ai_assisted')) {
                continue;
            }
            $available[] = ['workflow_id' => $workflow->id, 'name' => $workflow->name, 'agents' => array_map(fn (array $agent): array => [
                'name' => $agent['label'] ?? 'AI Agent', 'specialization' => $agent['config']['specialization'] ?? 'custom',
                'responsibilities' => mb_substr($agent['config']['instruction'] ?? '', 0, 1500),
                'tools' => array_map(fn (array $app): array => $this->tools->describe([...$app, '_owner_key' => $ownerKey]), $graph->connectedApps($agent['id'])),
            ], $agents)];
        }

        return $available;
    }

    public function ask(string $ownerKey, int $workflowId, string $request): array
    {
        $allowed = array_column($this->available($ownerKey), 'workflow_id');
        if (! in_array($workflowId, $allowed, true)) {
            return ['success' => false, 'error' => 'That workflow is not available to this owner.'];
        }
        $workflow = $this->workflows($ownerKey)->firstWhere('id', $workflowId);
        $run = $this->executor->run($workflow, ['request' => mb_substr($request, 0, 10000)], 'chat', chatRequest: true);
        $final = $run['execution']->output_data['final'] ?? [];

        return ['success' => $run['success'], 'source' => $final['agent'] ?? 'Workflow Agent',
            'summary' => $final['result']['summary'] ?? ($run['error'] ?? null), 'result' => $final['result'] ?? null,
            'data' => $final['tools'] ?? [], 'trace' => $final['trace'] ?? null, 'execution_id' => $run['execution']->id,
            'error' => $run['error'] ?? null];
    }

    private function workflows(string $ownerKey): Collection
    {
        return JarvisWorkflow::query()->where('owner_key', $ownerKey)->whereNotIn('status', ['paused', 'invalid'])
            ->orderByDesc('last_used_at')->orderByDesc('id')->limit(20)->get();
    }
}
