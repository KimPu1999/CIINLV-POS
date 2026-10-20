<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceOrderEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['marketplace_order_id', 'marketplace_vendor_order_id', 'actor_user_id', 'event', 'from_status', 'to_status', 'metadata', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVendorOrder::class, 'marketplace_vendor_order_id');
    }
}
