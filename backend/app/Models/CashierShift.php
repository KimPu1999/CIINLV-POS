<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashierShift extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'register_id', 'cashier_user_id', 'opening_amount', 'closing_amount', 'expected_amount', 'actual_amount', 'cash_difference', 'status', 'opened_at', 'closed_at'];

    protected static function booted(): void
    {
        static::creating(fn (CashierShift $shift) => $shift->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['opening_amount' => 'decimal:2', 'closing_amount' => 'decimal:2', 'expected_amount' => 'decimal:2', 'actual_amount' => 'decimal:2', 'cash_difference' => 'decimal:2', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
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

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cashier_shift_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'cashier_shift_id');
    }
}
