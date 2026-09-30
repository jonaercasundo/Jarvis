<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JarvisBilling extends Model
{
    protected $table = 'jarvis_billing';

    protected $fillable = [
        'provider',
        'starting_balance_usd',
        'current_balance_usd',
        'total_spent_usd',
        'last_synced_at',
    ];

    protected $casts = [
        'starting_balance_usd' => 'decimal:6',
        'current_balance_usd' => 'decimal:6',
        'total_spent_usd' => 'decimal:6',
        'last_synced_at' => 'datetime',
    ];

    public function usages(): HasMany
    {
        return $this->hasMany(JarvisBillingUsage::class);
    }
}
