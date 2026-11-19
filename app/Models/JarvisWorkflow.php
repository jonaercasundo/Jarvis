<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JarvisWorkflow extends Model
{
    protected $fillable = [
        'owner_key',
        'name',
        'description',
        'workflow_data',
        'trigger_state',
        'last_used_at',
        'status',
        'webhook_secret',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'workflow_data' => 'array',
            'trigger_state' => 'array',
            'webhook_secret' => 'encrypted',
            'last_run_at' => 'datetime',
            'last_used_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function executions(): HasMany
    {
        return $this->hasMany(JarvisWorkflowExecution::class, 'workflow_id');
    }

    public function agentTasks(): HasMany
    {
        return $this->hasMany(WorkflowAgentTask::class, 'workflow_id');
    }
}
