<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessInvitation extends Model
{
    protected $fillable = [
        'tenant_id', 'email', 'role', 'token_hash', 'expires_at', 'sent_at', 'failed_at', 'failure_reason', 'accepted_at', 'revoked_at', 'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }

    public function deliverySecretCacheKey(): string
    {
        return 'business-invitation:delivery-secret:'.$this->getKey();
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return $this->accepted_at
            ? 'accepted'
            : ($this->revoked_at
                ? 'revoked'
                : ($this->failed_at
                    ? 'failed'
                    : ($this->expires_at?->isPast()
                        ? 'expired'
                        : ($this->sent_at ? 'sent' : 'pending'))));
    }
}
