<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceReturnRequest extends Model
{
    protected $fillable = ['marketplace_order_id', 'customer_user_id', 'requested_items', 'reason', 'status', 'decision_note', 'decided_by', 'decided_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceReturnRequest $request) => $request->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['requested_items' => 'array', 'decided_at' => 'datetime'];
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
}
