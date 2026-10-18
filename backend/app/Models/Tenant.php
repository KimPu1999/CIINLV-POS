<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $fillable = [
        'name', 'slug', 'business_code', 'email', 'phone', 'address', 'status', 'subscription_status', 'settings', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'suspended_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_memberships')
            ->withPivot(['role_id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function businessSetting(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(BusinessInvitation::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function storeMemberships(): HasMany
    {
        return $this->hasMany(StoreMembership::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function inventoryLocations(): HasMany
    {
        return $this->hasMany(InventoryLocation::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
