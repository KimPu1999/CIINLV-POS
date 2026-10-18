<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockAdjustment extends Model
{
    protected $fillable = ['tenant_id', 'inventory_location_id', 'product_variant_id', 'quantity_delta', 'reason', 'status', 'created_by', 'idempotency_key'];

    protected static function booted(): void
    {
        static::creating(fn (StockAdjustment $adjustment) => $adjustment->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:3'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
