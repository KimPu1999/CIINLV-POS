<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessNotification extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'store_id', 'type', 'title', 'message', 'data',
        'priority', 'dedupe_key', 'read_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (BusinessNotification $notification): ?string => $notification->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
