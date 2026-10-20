<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplacePayment extends Model
{
    protected $fillable = ['marketplace_order_id', 'method', 'provider', 'status', 'amount', 'currency_code', 'provider_reference', 'idempotency_key', 'metadata', 'paid_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplacePayment $payment) => $payment->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array', 'paid_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
