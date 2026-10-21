<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformHealthHeartbeat extends Model
{
    protected $fillable = [
        'key',
        'status',
        'last_success_at',
        'last_failure_at',
        'last_error',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
