<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JarvisWorkflowExecutionStep extends Model
{
    protected $fillable = [
        'execution_id',
        'node_id',
        'node_type',
        'execution_order',
        'status',
        'input_data',
        'output_data',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'array',
            'output_data' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(JarvisWorkflowExecution::class, 'execution_id');
    }
}
