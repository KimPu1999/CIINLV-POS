<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SaleReturnItem extends Model
{
    protected $fillable = ['tenant_id', 'sale_return_id', 'sale_item_id', 'product_variant_id', 'quantity', 'unit_refund_amount', 'refund_amount', 'restock', 'disposition'];

    protected static function booted(): void
    {
        static::creating(fn (SaleReturnItem $item) => $item->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_refund_amount' => 'decimal:2', 'refund_amount' => 'decimal:2', 'restock' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
