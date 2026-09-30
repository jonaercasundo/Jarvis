<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JarvisBillingUsage extends Model
{
    protected $table = 'jarvis_billing_usages';

    protected $fillable = [
        'jarvis_billing_id',
        'provider',
        'model',
        'request_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cost_usd',
        'used_at',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'cost_usd' => 'decimal:8',
        'used_at' => 'datetime',
    ];

    public function billing(): BelongsTo
    {
        return $this->belongsTo(JarvisBilling::class);
    }
}
