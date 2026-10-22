<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketplaceOrder extends Model
{
    protected $fillable = ['customer_user_id', 'order_number', 'invoice_number', 'promotion_code', 'currency_code', 'status', 'payment_status', 'subtotal', 'discount_total', 'shipping_total', 'tax_total', 'grand_total', 'delivery_address', 'idempotency_key', 'request_hash'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceOrder $order) => $order->uuid ??= (string) Str::uuid());
        static::created(function (MarketplaceOrder $order): void {
            if (! $order->invoice_number) {
                $order->forceFill(['invoice_number' => 'INV-MP-'.now()->format('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'shipping_total' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'delivery_address' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function vendorOrders(): HasMany
    {
        return $this->hasMany(MarketplaceVendorOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MarketplacePayment::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(MarketplaceInventoryReservation::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MarketplaceOrderEvent::class);
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(MarketplaceCancellationRequest::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(MarketplaceReturnRequest::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(MarketplaceRefundRequest::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(MarketplaceOrderCommission::class);
    }

    public function settlementEntries(): HasMany
    {
        return $this->hasMany(MarketplaceSettlementEntry::class);
    }
}
