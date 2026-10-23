<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = ['tenant_id', 'marketplace_listing_id', 'product_id', 'marketplace_order_item_id', 'customer_user_id', 'rating', 'title', 'comment', 'status', 'verified_purchase', 'moderated_by', 'moderation_reason', 'moderated_at'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceReview $review) => $review->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['verified_purchase' => 'boolean', 'moderated_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(MarketplaceListing::class, 'marketplace_listing_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrderItem::class, 'marketplace_order_item_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function moderationHistory(): HasMany
    {
        return $this->hasMany(MarketplaceReviewModerationHistory::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MarketplaceReviewReport::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('marketplace_reviews.status', self::STATUS_APPROVED);
    }
}
