<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JarvisWorkflowExecution extends Model
{
    protected $fillable = [
        'workflow_id',
        'trigger_type',
        'status',
        'started_at',
        'finished_at',
        'input_data',
        'output_data',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'input_data' => 'array',
            'output_data' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(JarvisWorkflow::class, 'workflow_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(JarvisWorkflowExecutionStep::class, 'execution_id')->orderBy('execution_order');
    }

    public function agentTasks(): HasMany
    {
        return $this->hasMany(WorkflowAgentTask::class, 'execution_id');
    }
}
