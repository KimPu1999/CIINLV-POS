<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceListing extends Model
{
    protected $fillable = ['tenant_id', 'marketplace_store_id', 'product_id', 'slug', 'status', 'published_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceListing $listing) => $listing->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(MarketplaceListingImage::class)->orderBy('sort_order');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(MarketplaceReview::class, 'marketplace_listing_id');
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->approved()->latest();
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where('marketplace_listings.status', 'published')
            ->whereNotNull('marketplace_listings.published_at')
            ->where('marketplace_listings.published_at', '<=', now())
            ->whereHas('store', fn (Builder $store) => $store->public())
            ->whereHas('product', function (Builder $product): void {
                $product->where('products.status', 'active')
                    ->whereHas('variants', fn (Builder $variant) => $variant->where('product_variants.status', 'active'))
                    ->where(function (Builder $availability): void {
                        $availability
                            ->where('products.track_inventory', false)
                            ->orWhereHas('variants.balances', fn (Builder $balance) => $balance->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0'));
                    });
            });
    }

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('marketplace_listings.tenant_id', $tenantId);
    }
}
