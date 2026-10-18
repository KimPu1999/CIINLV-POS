<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductVariant extends Model
{
    protected $fillable = ['tenant_id', 'product_id', 'sku', 'barcode', 'barcode_type', 'unit', 'attributes', 'purchase_cost', 'selling_price', 'reorder_level', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (ProductVariant $variant) => $variant->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['attributes' => 'array', 'purchase_cost' => 'decimal:4', 'selling_price' => 'decimal:4', 'reorder_level' => 'decimal:3'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
