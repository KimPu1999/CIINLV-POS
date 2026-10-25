<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class MarketplaceDispute extends Model
{
    public const STATUSES = ['open', 'awaiting_seller_response', 'under_review', 'awaiting_customer_information', 'resolved', 'rejected', 'closed'];
    public const CATEGORIES = ['payment_problem', 'order_problem', 'seller_not_responding', 'shipping_delay', 'tracking_problem', 'product_not_received', 'delivery_delay', 'wrong_product', 'damaged_or_defective', 'missing_items', 'not_as_described', 'refund_problem', 'pickup_unavailable', 'pickup_delay', 'other'];
    public const RESOLUTIONS = ['full_refund', 'partial_refund', 'replacement', 'return_requested', 'no_refund', 'other'];

    protected $fillable = ['marketplace_order_id', 'marketplace_vendor_order_id', 'marketplace_order_item_id', 'tenant_id', 'customer_user_id', 'assigned_reviewer_id', 'category', 'subject', 'description', 'status', 'priority', 'requested_resolution', 'resolution_note', 'resolved_at'];

    protected static function booted(): void
    {
        static::creating(function (MarketplaceDispute $dispute): void {
            $dispute->uuid ??= (string) Str::uuid();
            $dispute->reference ??= 'DSP-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));
        });
    }

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function order(): BelongsTo { return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id'); }
    public function vendorOrder(): BelongsTo { return $this->belongsTo(MarketplaceVendorOrder::class, 'marketplace_vendor_order_id'); }
    public function item(): BelongsTo { return $this->belongsTo(MarketplaceOrderItem::class, 'marketplace_order_item_id'); }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class, 'customer_user_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'assigned_reviewer_id'); }
    public function evidence(): HasMany { return $this->hasMany(MarketplaceDisputeEvidence::class); }
    public function messages(): HasMany { return $this->hasMany(MarketplaceDisputeMessage::class); }
    public function history(): HasMany { return $this->hasMany(MarketplaceDisputeStatusHistory::class); }
    public function resolution(): HasOne { return $this->hasOne(MarketplaceDisputeResolution::class); }
}
