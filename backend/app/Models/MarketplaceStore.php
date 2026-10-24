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
        'tenant_id', 'name', 'slug', 'description', 'tagline', 'public_contact_email', 'public_contact_phone',
        'secondary_contact_phone', 'business_category', 'website', 'facebook_url', 'messaging_contact',
        'shipping_coverage', 'support_contact', 'cod_available', 'delivery_methods', 'operating_hours',
        'temporary_closure_message', 'holiday_notice', 'policies', 'logo_path', 'banner_path',
        'approval_status', 'is_active', 'notification_preferences', 'fulfillment_preferences', 'approved_at', 'approved_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceStore $store) => $store->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'cod_available' => 'boolean',
            'approved_at' => 'datetime',
            'notification_preferences' => 'array',
            'fulfillment_preferences' => 'array',
            'delivery_methods' => 'array',
            'operating_hours' => 'array',
            'policies' => 'array',
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

    public function vendorOrders(): HasMany
    {
        return $this->hasMany(MarketplaceVendorOrder::class, 'marketplace_store_id');
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query
            ->where('approval_status', 'approved')
            ->where('is_active', true)
            ->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active'));
    }
}
