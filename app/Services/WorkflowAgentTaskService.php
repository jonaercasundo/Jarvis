<?php

namespace App\Services;

use App\Models\JarvisWorkflow;
use App\Models\JarvisWorkflowExecution;
use App\Models\WorkflowAgentTask;

class WorkflowAgentTaskService
{
    /** @param array<string, mixed> $input */
    public function start(
        JarvisWorkflow $workflow,
        JarvisWorkflowExecution $execution,
        array $agent,
        array $input,
    ): WorkflowAgentTask {
        return $execution->agentTasks()->create([
            'workflow_id' => $workflow->id,
            'agent_node_id' => $agent['id'],
            'task_type' => (string) ($agent['config']['task_type'] ?? 'workflow_execution'),
            'status' => 'running',
            'priority' => (int) ($agent['config']['priority'] ?? 50),
            'input_data' => $input,
            'started_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $result */
    public function complete(WorkflowAgentTask $task, array $result): void
    {
        $task->update([
            'status' => 'completed',
            'result_data' => $result,
            'finished_at' => now(),
        ]);
    }

    public function fail(WorkflowAgentTask $task, string $error): void
    {
        $task->update([
            'status' => 'failed',
            'error_message' => mb_substr($error, 0, 10000),
            'finished_at' => now(),
        ]);
    }
}
