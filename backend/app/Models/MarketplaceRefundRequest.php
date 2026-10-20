<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceRefundRequest extends Model
{
    protected $fillable = ['marketplace_order_id', 'customer_user_id', 'marketplace_return_request_id', 'requested_amount', 'approved_amount', 'currency_code', 'reason', 'status', 'provider', 'provider_reference', 'review_note', 'reviewed_by', 'reviewed_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceRefundRequest $request) => $request->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['requested_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'reviewed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(MarketplaceReturnRequest::class, 'marketplace_return_request_id');
    }
}
