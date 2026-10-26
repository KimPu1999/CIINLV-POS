<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CashFund extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'name', 'fund_type', 'opening_balance', 'approval_limit', 'status', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (CashFund $fund) => $fund->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'approval_limit' => 'decimal:2'];
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

    public function entries(): HasMany
    {
        return $this->hasMany(CashManagementEntry::class, 'fund_id');
    }
}
