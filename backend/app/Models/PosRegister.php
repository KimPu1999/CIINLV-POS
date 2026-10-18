<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PosRegister extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'name', 'code', 'status', 'settings', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (PosRegister $register) => $register->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['settings' => 'array'];
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class, 'register_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'register_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PosRegisterMembership::class, 'register_id');
    }
}
