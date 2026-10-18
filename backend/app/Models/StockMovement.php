<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'inventory_location_id', 'product_variant_id', 'movement_type', 'quantity_delta', 'quantity_before', 'quantity_after', 'reference_type', 'reference_id', 'reason', 'actor_user_id', 'idempotency_key', 'created_at'];

    protected static function booted(): void
    {
        static::creating(fn (StockMovement $movement) => $movement->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['quantity_delta' => 'decimal:3', 'quantity_before' => 'decimal:3', 'quantity_after' => 'decimal:3', 'created_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'inventory_location_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function boot(): void
    {
        parent::boot();
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }
}
