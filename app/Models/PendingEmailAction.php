<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PendingEmailAction extends Model
{
    protected $fillable = [
        'token',
        'action',
        'email_id',
        'payload',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];

    /**
     * Create a pending action awaiting user confirmation.
     */
    public static function createPending(
        string $action,
        string $emailId,
        array $payload,
        int $expiresInMinutes = 30,
    ): self {
        return static::create([
            'token' => strtoupper(Str::random(6)),
            'action' => $action,
            'email_id' => $emailId,
            'payload' => $payload,
            'status' => 'pending',
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);
    }

    /**
     * Scope to actions that are still pending and not expired.
     */
    public function scopeActionable(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
