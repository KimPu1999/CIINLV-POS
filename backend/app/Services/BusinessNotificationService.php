<?php

namespace App\Services;

use App\Models\BusinessNotification;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceVendorOrder;
use App\Models\StockAlert;
use App\Models\TenantMembership;

class BusinessNotificationService
{
    public function orderCreated(MarketplaceOrder $order): void
    {
        $order->loadMissing(['customer', 'vendorOrders.store']);

        foreach ($order->vendorOrders as $vendorOrder) {
            $customer = $order->customer?->name ?: $order->customer?->email ?: 'A customer';
            $this->notifyTenant(
                (int) $vendorOrder->tenant_id,
                (int) $vendorOrder->marketplace_store_id,
                'marketplace_order_created',
                'New Marketplace Order',
                sprintf('%s has been received from %s for %s %s.', $vendorOrder->vendor_order_number, $customer, $order->currency_code, $vendorOrder->total),
                [
                    'order_id' => $order->uuid,
                    'vendor_order_id' => $vendorOrder->uuid,
                    'vendor_order_number' => $vendorOrder->vendor_order_number,
                    'tenant_id' => (int) $vendorOrder->tenant_id,
                    'store_id' => (int) $vendorOrder->marketplace_store_id,
                    'store_uuid' => $vendorOrder->store?->uuid,
                    'customer_name' => $customer,
                    'order_total' => (string) $vendorOrder->total,
                    'currency_code' => $order->currency_code,
                    'related_url' => '/business/marketplace/orders/'.$vendorOrder->uuid,
                ],
                'normal',
                'marketplace_order_created:'.$vendorOrder->uuid,
            );
        }
    }

    public function paymentReceived(MarketplaceOrder $order, ?MarketplaceVendorOrder $vendorOrder, MarketplacePayment $payment): void
    {
        $order->loadMissing(['customer', 'vendorOrders.store']);
        $vendorOrders = $vendorOrder ? collect([$vendorOrder]) : $order->vendorOrders;

        foreach ($vendorOrders as $currentVendorOrder) {
            $this->notifyTenant(
                (int) $currentVendorOrder->tenant_id,
                (int) $currentVendorOrder->marketplace_store_id,
                'marketplace_payment_received',
                'Payment Received',
                sprintf('Payment for order %s has been received.', $currentVendorOrder->vendor_order_number),
                [
                    'order_id' => $order->uuid,
                    'vendor_order_id' => $currentVendorOrder->uuid,
                    'payment_id' => $payment->uuid,
                    'payment_reference' => $payment->provider_reference ?: $payment->transaction_reference,
                    'amount' => (string) $payment->amount,
                    'currency_code' => $payment->currency_code,
                    'method' => $payment->method,
                    'related_url' => '/business/marketplace/orders/'.$currentVendorOrder->uuid,
                ],
                'normal',
                'marketplace_payment_received:'.$payment->uuid.':'.$currentVendorOrder->uuid,
            );
        }
    }

    public function fulfillmentReady(MarketplaceVendorOrder $vendorOrder): void
    {
        $vendorOrder->loadMissing(['order', 'store']);

        $this->notifyTenant(
            (int) $vendorOrder->tenant_id,
            (int) $vendorOrder->marketplace_store_id,
            'marketplace_fulfillment_ready',
            'Order Ready for Fulfillment',
            sprintf('Order %s is ready to be processed.', $vendorOrder->vendor_order_number),
            [
                'order_id' => $vendorOrder->order?->uuid,
                'vendor_order_id' => $vendorOrder->uuid,
                'related_url' => '/business/marketplace/orders/'.$vendorOrder->uuid,
            ],
            'normal',
            'marketplace_fulfillment_ready:'.$vendorOrder->uuid,
        );
    }

    public function inventoryAlert(StockAlert $alert): void
    {
        $alert->loadMissing(['location.store', 'variant.product']);
        $variant = $alert->variant;
        $productName = $variant?->product?->name ?: $variant?->sku ?: 'Product';
        $available = (string) $alert->available_quantity;
        $threshold = (string) $alert->threshold_quantity;
        $outOfStock = $alert->alert_type === 'out_of_stock';

        $this->notifyTenant(
            (int) $alert->tenant_id,
            $alert->location?->store_id,
            $outOfStock ? 'inventory_out_of_stock' : 'inventory_low_stock',
            $outOfStock ? 'Out of Stock' : 'Low Stock',
            $outOfStock
                ? sprintf('%s is out of stock.', $productName)
                : sprintf('%s is running low. Only %s remaining.', $productName, $available),
            [
                'stock_alert_id' => $alert->uuid,
                'product_id' => $variant?->product?->uuid,
                'variant_id' => $variant?->uuid,
                'sku' => $variant?->sku,
                'available_stock' => $available,
                'reorder_level' => $threshold,
                'tenant_id' => (int) $alert->tenant_id,
                'store_id' => $alert->location?->store_id,
                'store_uuid' => $alert->location?->store?->uuid,
                'related_url' => '/business/inventory?variant='.($variant?->uuid ?: ''),
            ],
            $outOfStock ? 'high' : 'normal',
            'inventory:'.$alert->uuid.':'.($alert->metadata['notification_cycle'] ?? $alert->first_triggered_at?->toIso8601String()).':'.$alert->alert_type,
        );
    }

    /** @param array<string, mixed> $data */
    private function notifyTenant(int $tenantId, ?int $storeId, string $type, string $title, string $message, array $data, string $priority, string $dedupeKey): void
    {
        $userIds = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            BusinessNotification::firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $userId, 'dedupe_key' => $dedupeKey],
                ['store_id' => $storeId, 'type' => $type, 'title' => $title, 'message' => $message, 'data' => $data, 'priority' => $priority],
            );
        }
    }
}
