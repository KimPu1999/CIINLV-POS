<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    protected $fillable = ['goods_receipt_id', 'product_variant_id', 'quantity_received', 'unit_cost'];

    protected function casts(): array
    {
        return ['quantity_received' => 'decimal:3', 'unit_cost' => 'decimal:4'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
