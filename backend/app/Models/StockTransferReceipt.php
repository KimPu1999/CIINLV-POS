<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockTransferReceipt extends Model
{
    protected $fillable = ['tenant_id', 'stock_transfer_id', 'received_by', 'idempotency_key', 'payload'];

    protected $casts = ['payload' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (StockTransferReceipt $receipt) => $receipt->uuid ??= (string) Str::uuid());
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
