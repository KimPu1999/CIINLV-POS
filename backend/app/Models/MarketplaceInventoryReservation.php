<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceInventoryReservation extends Model
{
    protected $fillable = ['marketplace_order_id', 'marketplace_order_item_id', 'tenant_id', 'inventory_balance_id', 'inventory_location_id', 'product_variant_id', 'quantity', 'status', 'idempotency_key', 'expires_at', 'released_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceInventoryReservation $reservation) => $reservation->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'expires_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrderItem::class, 'marketplace_order_item_id');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(InventoryBalance::class, 'inventory_balance_id');
    }
}
