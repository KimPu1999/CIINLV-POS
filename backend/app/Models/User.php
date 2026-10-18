<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'platform_role', 'status', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const PLATFORM_ROLE_SUPER_ADMIN = 'super_admin';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITED = 'invited';

    public const STATUS_SUSPENDED = 'suspended';

    protected $appends = ['is_super_admin'];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_memberships')
            ->withPivot(['role_id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function getIsSuperAdminAttribute(): bool
    {
        return $this->platform_role === self::PLATFORM_ROLE_SUPER_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->platform_role === self::PLATFORM_ROLE_SUPER_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
