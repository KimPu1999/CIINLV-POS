<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class MarketplaceVendorOrder extends Model
{
    protected $fillable = ['marketplace_order_id', 'tenant_id', 'marketplace_store_id', 'vendor_order_number', 'status', 'fulfillment_status', 'fulfillment_activated_at', 'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'total', 'currency_code', 'delivery_options'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceVendorOrder $order) => $order->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'shipping_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'total' => 'decimal:2', 'fulfillment_activated_at' => 'datetime', 'delivery_options' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(MarketplaceShipment::class, 'marketplace_vendor_order_id');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(MarketplaceOrderCommission::class, 'marketplace_vendor_order_id');
    }

    /**
     * Reservation locations are the authoritative historical fulfillment
     * attribution. Marketplace stores are seller profiles, not POS branches.
     */
    public function branchAttributionData(): array
    {
        $branches = [];
        $locations = [];

        foreach ($this->items ?? collect() as $item) {
            foreach ($item->reservations ?? collect() as $reservation) {
                $location = $reservation->balance?->location;
                $store = $location?->store;
                if ($store) {
                    $branches[(string) $store->id] = [
                        'key' => (int) $store->id,
                        'id' => $store->uuid,
                        'name' => $store->name,
                        'code' => $store->code,
                    ];
                }
                if ($location) {
                    $locations[(string) $location->id] = [
                        'branch_key' => $store ? (int) $store->id : null,
                        'id' => $location->uuid,
                        'name' => $location->name,
                        'code' => $location->code,
                        'branch_id' => $store?->uuid,
                        'branch_name' => $store?->name,
                    ];
                }
            }
        }

        return [
            'branches' => array_values($branches),
            'locations' => array_values($locations),
            'branch_count' => count($branches),
            'label' => match (count($branches)) {
                0 => 'Unassigned / Unknown Branch',
                1 => array_values($branches)[0]['name'],
                default => 'Multiple Branches',
            },
        ];
    }
}
