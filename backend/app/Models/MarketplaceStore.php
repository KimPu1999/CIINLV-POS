<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceStore extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'public_contact_email', 'public_contact_phone',
        'business_category', 'logo_path', 'banner_path', 'approval_status', 'is_active',
        'notification_preferences', 'fulfillment_preferences', 'approved_at', 'approved_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceStore $store) => $store->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'approved_at' => 'datetime',
            'notification_preferences' => 'array',
            'fulfillment_preferences' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(MarketplaceListing::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where('approval_status', 'approved')
            ->where('is_active', true)
            ->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active'));
    }
}
