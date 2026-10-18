<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InventoryLocation extends Model
{
    protected $fillable = ['tenant_id', 'location_type', 'store_id', 'name', 'code', 'address', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (InventoryLocation $location) => $location->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
