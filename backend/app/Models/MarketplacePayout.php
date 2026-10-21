<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplacePayout extends Model
{
    protected $fillable = ['tenant_id', 'currency_code', 'amount', 'status', 'provider', 'provider_reference', 'idempotency_key', 'requested_by', 'requested_at', 'approved_by', 'approved_at', 'processed_by', 'processed_at', 'reason', 'metadata'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplacePayout $payout) => $payout->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'processed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplacePayoutItem::class);
    }
}
