<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceOrderItem extends Model
{
    protected $fillable = ['marketplace_order_id', 'marketplace_vendor_order_id', 'tenant_id', 'marketplace_listing_id', 'product_id', 'product_variant_id', 'product_name_snapshot', 'sku_snapshot', 'variant_snapshot', 'quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total', 'tax_snapshot', 'currency_code'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceOrderItem $item) => $item->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2', 'variant_snapshot' => 'array', 'tax_snapshot' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVendorOrder::class, 'marketplace_vendor_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(MarketplaceInventoryReservation::class);
    }
}
