<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SaleItem extends Model
{
    protected $fillable = ['tenant_id', 'sale_id', 'product_variant_id', 'product_name_snapshot', 'sku_snapshot', 'barcode_snapshot', 'unit_snapshot', 'quantity', 'returned_quantity', 'unit_price', 'discount_amount', 'tax_amount', 'line_total', 'unit_cost_at_sale', 'tax_snapshot'];

    protected static function booted(): void
    {
        static::creating(fn (SaleItem $item) => $item->uuid ??= (string) Str::uuid());
        static::updating(function (SaleItem $item): bool {
            if ($item->sale?->status === 'completed' || in_array($item->sale?->status, ['partially_refunded', 'refunded'], true)) {
                $changed = array_diff(array_keys($item->getDirty()), ['returned_quantity', 'updated_at']);

                return $changed === [];
            }

            return true;
        });
        static::deleting(fn (): bool => false);
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'returned_quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2', 'unit_cost_at_sale' => 'decimal:4', 'tax_snapshot' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
