<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessIdempotencyKey extends Model
{
    protected $fillable = [
        'actor_user_id', 'idempotency_key', 'request_hash', 'tenant_id', 'response_status', 'response_payload',
    ];

    protected function casts(): array
    {
        return ['response_payload' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
