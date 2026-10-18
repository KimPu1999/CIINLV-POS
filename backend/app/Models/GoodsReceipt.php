<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GoodsReceipt extends Model
{
    protected $fillable = ['tenant_id', 'purchase_order_id', 'received_by', 'received_at', 'status', 'idempotency_key'];

    protected static function booted(): void
    {
        static::creating(fn (GoodsReceipt $receipt) => $receipt->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
