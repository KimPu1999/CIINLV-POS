<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceOrderCommission extends Model
{
    protected $fillable = ['marketplace_order_id', 'marketplace_vendor_order_id', 'tenant_id', 'marketplace_commission_rule_id', 'currency_code', 'commission_base_amount', 'commission_rate', 'commission_amount', 'seller_net_amount', 'status', 'calculated_at', 'settled_at', 'metadata'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceOrderCommission $commission) => $commission->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['commission_base_amount' => 'decimal:2', 'commission_rate' => 'decimal:4', 'commission_amount' => 'decimal:2', 'seller_net_amount' => 'decimal:2', 'calculated_at' => 'datetime', 'settled_at' => 'datetime', 'metadata' => 'array'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVendorOrder::class, 'marketplace_vendor_order_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCommissionRule::class, 'marketplace_commission_rule_id');
    }
}
