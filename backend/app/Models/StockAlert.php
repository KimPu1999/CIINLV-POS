<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockAlert extends Model
{
    protected $fillable = ['tenant_id', 'inventory_location_id', 'product_variant_id', 'alert_type', 'threshold_quantity', 'available_quantity', 'status', 'first_triggered_at', 'last_triggered_at', 'acknowledged_at', 'acknowledged_by', 'snoozed_until', 'snoozed_by', 'resolved_at', 'metadata'];

    protected static function booted(): void
    {
        static::creating(fn (StockAlert $alert) => $alert->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['threshold_quantity' => 'decimal:3', 'available_quantity' => 'decimal:3', 'first_triggered_at' => 'datetime', 'last_triggered_at' => 'datetime', 'acknowledged_at' => 'datetime', 'snoozed_until' => 'datetime', 'resolved_at' => 'datetime', 'metadata' => 'array'];
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

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function snoozedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snoozed_by');
    }
}
