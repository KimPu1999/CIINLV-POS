<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Store extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'code', 'description', 'email', 'phone', 'address', 'city', 'country',
        'currency_code', 'timezone', 'status', 'settings', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Store $store): void {
            $store->uuid ??= (string) Str::uuid();
        });
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

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(TenantMembership::class, 'store_memberships')
            ->withPivot('status')
            ->withTimestamps();
    }

    public function storeMemberships(): HasMany
    {
        return $this->hasMany(StoreMembership::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(StoreSetting::class);
    }

    public function dashboardWidgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }

    public function dashboardLayouts(): HasMany
    {
        return $this->hasMany(DashboardLayout::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function registers(): HasMany
    {
        return $this->hasMany(PosRegister::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(CashierShift::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
