<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowAgentTask extends Model
{
    protected $table = 'jarvis_workflow_agent_tasks';

    protected $fillable = [
        'workflow_id',
        'execution_id',
        'agent_node_id',
        'task_type',
        'status',
        'priority',
        'input_data',
        'result_data',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'array',
            'result_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(JarvisWorkflow::class, 'workflow_id');
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(JarvisWorkflowExecution::class, 'execution_id');
    }
}
