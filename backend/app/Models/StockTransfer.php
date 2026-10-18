<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class StockTransfer extends Model
{
    protected $fillable = ['tenant_id', 'source_location_id', 'destination_location_id', 'transfer_number', 'status', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (StockTransfer $transfer) => $transfer->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'source_location_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'destination_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class, 'stock_transfer_id');
    }
}
