<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAnalysis extends Model
{
    protected $fillable = [
        'provider',
        'account',
        'message_id',
        'thread_id',
        'from_address',
        'subject',
        'classification',
        'priority',
        'requires_action',
        'time_sensitive',
        'business_impact',
        'notification_required',
        'reason',
        'action',
        'deadline',
        'summary',
        'analyzed_at',
        'notified_at',
        'notification_token',
        'notification_claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'requires_action' => 'boolean',
            'time_sensitive' => 'boolean',
            'business_impact' => 'boolean',
            'notification_required' => 'boolean',
            'analyzed_at' => 'datetime',
            'notified_at' => 'datetime',
            'notification_claimed_at' => 'datetime',
        ];
    }
}
