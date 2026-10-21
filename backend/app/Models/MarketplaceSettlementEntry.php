<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceSettlementEntry extends Model
{
    protected $fillable = ['marketplace_order_id', 'marketplace_vendor_order_id', 'marketplace_order_commission_id', 'tenant_id', 'entry_type', 'direction', 'amount', 'currency_code', 'status', 'idempotency_key', 'metadata'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceSettlementEntry $entry) => $entry->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }
}
