<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JarvisCreditUsage extends Model
{
    protected $fillable = [
        'conversation_id',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'credits',
    ];
}
