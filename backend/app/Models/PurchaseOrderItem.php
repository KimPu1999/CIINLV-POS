<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_variant_id', 'ordered_quantity', 'unit_cost', 'received_quantity'];

    protected function casts(): array
    {
        return ['ordered_quantity' => 'decimal:3', 'unit_cost' => 'decimal:4', 'received_quantity' => 'decimal:3'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
