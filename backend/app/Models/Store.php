<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Store extends Model
{
    protected $fillable = ['tenant_id', 'name', 'code', 'status', 'settings'];

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
}
