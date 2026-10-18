<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    protected $fillable = ['tenant_id', 'inventory_location_id', 'product_variant_id', 'on_hand_quantity', 'reserved_quantity', 'average_cost', 'version'];

    protected function casts(): array
    {
        return ['on_hand_quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3', 'average_cost' => 'decimal:4'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getAvailableQuantityAttribute(): string
    {
        return bcsub((string) $this->on_hand_quantity, (string) $this->reserved_quantity, 3);
    }
}
