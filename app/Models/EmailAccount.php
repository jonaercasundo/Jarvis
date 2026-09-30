<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailAccount extends Model
{
    protected $fillable = [
        'name',
        'email',

        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',

        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',

        'is_default',
        'is_active',
    ];

    protected $casts = [
        'imap_password' => 'encrypted',
        'smtp_password' => 'encrypted',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];
}
