<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TenantMembership extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'role_id', 'status', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_memberships')
            ->withPivot('status')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->role?->name !== null;
    }
}
