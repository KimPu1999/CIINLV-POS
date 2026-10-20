<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceCartItem extends Model
{
    protected $fillable = ['marketplace_cart_id', 'tenant_id', 'marketplace_listing_id', 'product_id', 'product_variant_id', 'quantity'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceCartItem $item) => $item->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCart::class, 'marketplace_cart_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
