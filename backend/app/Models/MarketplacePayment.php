<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplacePayment extends Model
{
    protected $fillable = ['marketplace_order_id', 'tenant_id', 'marketplace_vendor_order_id', 'marketplace_store_id', 'method', 'provider', 'status', 'amount', 'amount_received', 'change_amount', 'currency_code', 'provider_reference', 'transaction_reference', 'received_by', 'received_at', 'receipt_number', 'idempotency_key', 'metadata', 'paid_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplacePayment $payment) => $payment->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'amount_received' => 'decimal:2', 'change_amount' => 'decimal:2', 'metadata' => 'array', 'paid_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVendorOrder::class, 'marketplace_vendor_order_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
