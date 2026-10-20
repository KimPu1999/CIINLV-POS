<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceCancellationRequest extends Model
{
    protected $fillable = ['marketplace_order_id', 'customer_user_id', 'reason', 'status', 'decision_note', 'decided_by', 'decided_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceCancellationRequest $request) => $request->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
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

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
