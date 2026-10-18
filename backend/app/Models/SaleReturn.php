<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class SaleReturn extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'sale_id', 'return_number', 'status', 'reason', 'refund_total', 'requested_by', 'approved_by', 'idempotency_key', 'request_hash', 'requested_at', 'approved_at', 'refunded_at'];

    protected static function booted(): void
    {
        static::creating(fn (SaleReturn $return) => $return->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['refund_total' => 'decimal:2', 'requested_at' => 'datetime', 'approved_at' => 'datetime', 'refunded_at' => 'datetime'];
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}
