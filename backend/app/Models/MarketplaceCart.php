<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceCart extends Model
{
    protected $fillable = ['customer_user_id', 'currency_code', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceCart $cart) => $cart->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceCartItem::class);
    }
}
