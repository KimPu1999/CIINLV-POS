<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceShipment extends Model
{
    protected $fillable = ['marketplace_vendor_order_id', 'tenant_id', 'delivery_type', 'carrier_name', 'tracking_number', 'tracking_url', 'internal_tracking_id', 'status', 'shipped_at', 'delivered_at', 'delivery_notes', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceShipment $shipment) => $shipment->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['shipped_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVendorOrder::class, 'marketplace_vendor_order_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceShipmentEvent::class, 'marketplace_shipment_id')->orderBy('event_time');
    }
}
