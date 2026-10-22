<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'sale_id', 'invoice_id', 'method', 'amount', 'currency_code', 'status', 'external_reference', 'provider', 'verified_at', 'collected_by', 'idempotency_key', 'request_hash', 'metadata'];

    protected static function booted(): void
    {
        static::creating(fn (Payment $payment) => $payment->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'verified_at' => 'datetime', 'metadata' => 'array'];
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

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
