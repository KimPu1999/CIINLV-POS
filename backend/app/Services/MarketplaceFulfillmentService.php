<?php

namespace App\Services;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceVendorOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceFulfillmentService
{
    public function __construct(
        private readonly MarketplaceInventoryReservationService $reservations,
    ) {}

    public function createShipment(MarketplaceVendorOrder $vendorOrder, User $actor, array $data): MarketplaceShipment
    {
        return DB::transaction(function () use ($vendorOrder, $actor, $data): MarketplaceShipment {
            $lockedOrder = MarketplaceVendorOrder::query()->whereKey($vendorOrder->id)->lockForUpdate()->firstOrFail();
            $lockedOrder->load(['order.payments', 'store', 'items']);

            if ($lockedOrder->shipments()->exists()) {
                throw new ConflictHttpException('A shipment already exists for this vendor order.');
            }
            if (! in_array($lockedOrder->status, ['pending', 'processing'], true)) {
                throw new ConflictHttpException('This vendor order is not eligible for shipment creation.');
            }
            if (! in_array($lockedOrder->order?->status, ['confirmed', 'processing'], true)) {
                throw new ConflictHttpException('This order must be confirmed before fulfillment can be activated.');
            }

            $this->assertPaymentMayShip($lockedOrder->order);
            $shipment = MarketplaceShipment::create([
                'marketplace_vendor_order_id' => $lockedOrder->id,
                'tenant_id' => $lockedOrder->tenant_id,
                'delivery_type' => $data['delivery_type'],
                'carrier_name' => $data['carrier_name'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'tracking_url' => $data['tracking_url'] ?? null,
                'status' => 'ready',
                'delivery_notes' => $data['delivery_notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $shipment->update(['internal_tracking_id' => 'CIINLV-DEL-'.now()->format('Y').'-'.str_pad((string) $shipment->id, 6, '0', STR_PAD_LEFT)]);
            $shipment->events()->create(['status' => 'ready', 'description' => 'Shipment created.', 'event_time' => now(), 'actor_user_id' => $actor->id]);

            if ($lockedOrder->status === 'pending') {
                $this->updateVendorStatus($lockedOrder, 'processing', $actor, 'vendor_order_accepted');
            }
            $this->syncMasterStatus($lockedOrder->order, $actor);

            return $shipment->refresh()->load(['vendorOrder.store', 'events.actor']);
        });
    }

    public function transitionShipment(MarketplaceShipment $shipment, User $actor, string $nextStatus, ?string $description = null): MarketplaceShipment
    {
        return DB::transaction(function () use ($shipment, $actor, $nextStatus, $description): MarketplaceShipment {
            $lockedShipment = MarketplaceShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            $lockedShipment->load(['vendorOrder.order.payments', 'vendorOrder.store']);
            $current = $lockedShipment->status;
            $allowed = [
                'ready' => ['shipped', 'cancelled'],
                'shipped' => ['in_transit', 'delivery_failed'],
                'in_transit' => ['delivered', 'delivery_failed', 'returned'],
                'delivery_failed' => ['in_transit', 'returned'],
                'delivered' => [],
                'returned' => [],
                'cancelled' => [],
            ];

            if (! in_array($nextStatus, $allowed[$current] ?? [], true)) {
                throw new ConflictHttpException(sprintf('Shipment status cannot change from %s to %s.', $current, $nextStatus));
            }
            if ($nextStatus === 'shipped') {
                $this->assertPaymentMayShip($lockedShipment->vendorOrder->order);
                $this->reservations->dispatchForVendorOrder($lockedShipment->vendorOrder, $actor);
                $lockedShipment->shipped_at = now();
            }
            if ($nextStatus === 'delivered') {
                $lockedShipment->delivered_at = now();
            }
            if ($nextStatus === 'cancelled') {
                $this->reservations->releaseForVendorOrder($lockedShipment->vendorOrder);
            }

            $lockedShipment->status = $nextStatus;
            $lockedShipment->save();
            $lockedShipment->events()->create(['status' => $nextStatus, 'description' => $description ?: $this->defaultEventDescription($nextStatus), 'event_time' => now(), 'actor_user_id' => $actor->id]);

            $vendorOrder = $lockedShipment->vendorOrder;
            $vendorStatus = match ($nextStatus) {
                'shipped', 'in_transit' => 'shipped',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => $vendorOrder->status,
            };
            if ($vendorStatus !== $vendorOrder->status) {
                $this->updateVendorStatus($vendorOrder, $vendorStatus, $actor, 'shipment_status_updated');
            }
            $this->syncMasterStatus($vendorOrder->order, $actor);

            return $lockedShipment->refresh()->load(['vendorOrder.store', 'events.actor']);
        });
    }

    public function syncMasterStatus(MarketplaceOrder $order, User $actor): MarketplaceOrder
    {
        $order->load('vendorOrders');
        $statuses = $order->vendorOrders->pluck('status')->values();
        if ($statuses->isEmpty()) {
            return $order;
        }
        if ($statuses->every(fn (string $status): bool => $status === 'cancelled')) {
            $next = 'cancelled';
        } elseif ($statuses->every(fn (string $status): bool => $status === 'delivered')) {
            $next = 'delivered';
        } elseif ($statuses->every(fn (string $status): bool => $status === 'shipped')) {
            $next = 'shipped';
        } elseif ($statuses->contains(fn (string $status): bool => in_array($status, ['shipped', 'delivered'], true))) {
            $next = 'partially_shipped';
        } elseif ($statuses->contains(fn (string $status): bool => $status === 'processing')) {
            $next = 'processing';
        } else {
            $next = 'confirmed';
        }

        if ($order->status !== $next) {
            $from = $order->status;
            $order->update(['status' => $next]);
            $order->events()->create(['actor_user_id' => $actor->id, 'event' => 'master_order_status_updated', 'from_status' => $from, 'to_status' => $next]);
        }

        return $order->refresh();
    }

    private function assertPaymentMayShip(MarketplaceOrder $order): void
    {
        $payment = $order->payments->sortByDesc('id')->first();
        if (! $payment) {
            throw new UnprocessableEntityHttpException('A verified payment is required before shipment.');
        }
        if ($payment->status === 'paid') {
            return;
        }
        if ($payment->method === 'cod' && $payment->status === 'pending') {
            return;
        }

        throw new ConflictHttpException('This order cannot ship until its payment is verified.');
    }

    private function updateVendorStatus(MarketplaceVendorOrder $order, string $next, User $actor, string $event): void
    {
        $from = $order->status;
        $order->update(['status' => $next]);
        $order->order?->events()->create(['marketplace_vendor_order_id' => $order->id, 'actor_user_id' => $actor->id, 'event' => $event, 'from_status' => $from, 'to_status' => $next]);
    }

    private function defaultEventDescription(string $status): string
    {
        return match ($status) {
            'shipped' => 'Shipment dispatched.',
            'in_transit' => 'Shipment is in transit.',
            'delivered' => 'Shipment delivered.',
            'delivery_failed' => 'Delivery attempt failed.',
            'returned' => 'Shipment returned.',
            'cancelled' => 'Shipment cancelled.',
            default => 'Shipment status updated.',
        };
    }
}
