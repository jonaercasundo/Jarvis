<?php

namespace App\Services;

use App\Models\ApiConnection;
use App\Models\JarvisWorkflow;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class WorkflowAgentApproval
{
    public function __construct(private WorkflowAgentTools $tools, private WorkflowAppExecutorRegistry $executors) {}

    /** Claim a stored proposal once. Client and model arguments cannot replace the reviewed action. */
    public function approve(JarvisWorkflow $workflow, int $executionId): array
    {
        [$execution, $prepared] = DB::transaction(function () use ($workflow, $executionId): array {
            $execution = $workflow->executions()->whereKey($executionId)->lockForUpdate()->firstOrFail();
            $output = $execution->output_data ?? [];
            $proposal = data_get($output, 'final.result.pending_action');
            if (! is_array($proposal) || isset($output['approval']) || data_get($execution->input_data, 'dry_run') === true || $execution->started_at->lt(now()->subMinutes(30))) {
                throw new RuntimeException('This approval is unavailable, expired or already used.');
            }
            $snapshot = data_get($execution->input_data, 'workflow_graph');
            if ($snapshot !== $workflow->workflow_data) {
                throw new RuntimeException('The workflow has changed. Run the task again before approving.');
            }
            $graph = new WorkflowGraph($snapshot);
            $agentId = collect($output['nodes'] ?? [])->first(fn (array $node): bool => data_get($node, 'output.status') === 'completed')['node_id'] ?? null;
            $agent = $agentId ? $graph->node($agentId) : null;
            $app = $agent ? collect($graph->connectedApps($agentId))->firstWhere('id', $proposal['tool_id']) : null;
            if (! $app || ! $agent) {
                throw new RuntimeException('The proposed tool is no longer connected to this Agent.');
            }
            $app['_owner_key'] = $workflow->owner_key;
            $values = $app['config']['values'] ?? [];
            $connection = ApiConnection::query()->where('owner_key', $workflow->owner_key)->whereKey($values['connection_id'] ?? $values['connection'] ?? 0)->first();
            if ($connection && data_get($execution->input_data, 'connection_revisions.'.$connection->id) !== $this->tools->connectionRevision($connection)) {
                throw new RuntimeException('The connection has changed. Run the task again before approving.');
            }
            $prepared = $this->tools->prepare($app, $proposal['action'], $proposal['arguments']);
            $permission = $this->tools->permission($app, $proposal['action']);
            if ((array_replace(WorkflowAgentRuntime::DEFAULT_PERMISSIONS, $agent['config']['permissions'] ?? [])[$permission] ?? false) !== true) {
                throw new RuntimeException('This Agent does not have permission to perform that action.');
            }
            $output['approval'] = ['status' => 'executing', 'approved_at' => now()->toIso8601String()];
            $execution->update(['output_data' => $output]);

            return [$execution, $prepared];
        });

        try {
            $result = $this->executors->execute($prepared);
            $status = ($result['success'] ?? true) ? 'success' : 'failed';
        } catch (Throwable $exception) {
            $result = ['success' => false, 'message' => $exception->getMessage()];
            $status = 'failed';
        }
        $secrets = ApiConnection::query()->where('owner_key', $workflow->owner_key)->get()->pluck('token')->filter()->all();
        $result = $this->tools->sanitize($result, $secrets);
        $output = $execution->fresh()->output_data;
        $output['approval'] = [...$output['approval'], 'status' => $status, 'result' => $result];
        $execution->update(['output_data' => $output]);

        return $output['approval'];
    }
}
