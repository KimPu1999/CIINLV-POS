<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CashReconciliation extends Model
{
    protected $fillable = [
        'tenant_id', 'store_id', 'fund_id', 'register_id', 'cashier_shift_id', 'expected_amount', 'actual_amount',
        'variance', 'status', 'notes', 'created_by', 'approved_by', 'approved_at', 'idempotency_key',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CashReconciliation $reconciliation) => $reconciliation->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:2', 'actual_amount' => 'decimal:2', 'variance' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(CashFund::class, 'fund_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }
}
