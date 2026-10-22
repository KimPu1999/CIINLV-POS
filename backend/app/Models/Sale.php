<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Sale extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'register_id', 'cashier_shift_id', 'cashier_user_id', 'salesperson_user_id', 'customer_id', 'sale_number', 'subtotal', 'discount_total', 'taxable_amount', 'tax_total', 'service_charge_total', 'fee_total', 'rounding_adjustment', 'grand_total', 'paid_total', 'balance_due', 'change_due', 'refunded_total', 'tax_breakdown', 'currency_code', 'status', 'payment_status', 'idempotency_key', 'request_hash', 'held_at', 'completed_at'];

    protected static function booted(): void
    {
        static::creating(fn (Sale $sale) => $sale->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'taxable_amount' => 'decimal:2', 'tax_total' => 'decimal:2', 'service_charge_total' => 'decimal:2', 'fee_total' => 'decimal:2', 'rounding_adjustment' => 'decimal:2', 'grand_total' => 'decimal:2', 'paid_total' => 'decimal:2', 'balance_due' => 'decimal:2', 'change_due' => 'decimal:2', 'refunded_total' => 'decimal:2', 'tax_breakdown' => 'array', 'held_at' => 'datetime', 'completed_at' => 'datetime'];
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

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_user_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SaleAdjustment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    public function invoice(): HasOne { return $this->hasOne(Invoice::class); }
}
