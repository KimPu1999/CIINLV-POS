<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $fillable = ['stock_transfer_id', 'product_variant_id', 'requested_quantity', 'reserved_quantity', 'dispatched_quantity', 'received_quantity', 'damaged_quantity', 'missing_quantity'];

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:3', 'reserved_quantity' => 'decimal:3', 'dispatched_quantity' => 'decimal:3', 'received_quantity' => 'decimal:3', 'damaged_quantity' => 'decimal:3', 'missing_quantity' => 'decimal:3'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
