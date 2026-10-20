<?php

namespace App\Services;

use App\Models\MarketplaceCancellationRequest;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRefundRequest;
use App\Models\MarketplaceReturnRequest;
use App\Models\MarketplaceVendorOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceOrderRequestService
{
    public function __construct(
        private readonly MarketplaceInventoryReservationService $reservations,
        private readonly MarketplaceFulfillmentService $fulfillment,
    ) {
    }

    public function createCancellation(MarketplaceOrder $order, User $customer, string $reason): MarketplaceCancellationRequest
    {
        $this->assertCustomer($order, $customer);
        return DB::transaction(function () use ($order, $customer, $reason): MarketplaceCancellationRequest {
            $lockedOrder = MarketplaceOrder::query()->whereKey($order->id)->lockForUpdate()->with(['vendorOrders.shipments', 'payments'])->firstOrFail();
            if (! in_array($lockedOrder->status, ['confirmed', 'processing'], true) || $lockedOrder->vendorOrders->contains(fn (MarketplaceVendorOrder $vendor): bool => $vendor->shipments->contains(fn ($shipment): bool => in_array($shipment->status, ['shipped', 'in_transit', 'delivered', 'returned'], true)))) {
                throw new ConflictHttpException('This order is no longer eligible for cancellation.');
            }
            if ($lockedOrder->payments->contains(fn ($payment): bool => $payment->status === 'paid')) {
                throw new ConflictHttpException('Paid orders require an approved refund workflow before cancellation.');
            }
            if (MarketplaceCancellationRequest::where('marketplace_order_id', $lockedOrder->id)->where('customer_user_id', $customer->id)->exists()) {
                throw new ConflictHttpException('A cancellation request already exists for this order.');
            }

            $request = MarketplaceCancellationRequest::create(['marketplace_order_id' => $lockedOrder->id, 'customer_user_id' => $customer->id, 'reason' => $reason, 'status' => 'pending']);
            $lockedOrder->events()->create(['actor_user_id' => $customer->id, 'event' => 'cancellation_requested', 'metadata' => ['request_id' => $request->uuid, 'reason' => $reason]]);

            return $request->load('order');
        });
    }

    public function createReturn(MarketplaceOrder $order, User $customer, string $reason, array $items): MarketplaceReturnRequest
    {
        $this->assertCustomer($order, $customer);
        return DB::transaction(function () use ($order, $customer, $reason, $items): MarketplaceReturnRequest {
            $lockedOrder = MarketplaceOrder::query()->whereKey($order->id)->lockForUpdate()->with(['vendorOrders', 'items'])->firstOrFail();
            if ($lockedOrder->status !== 'delivered' && ! $lockedOrder->vendorOrders->every(fn (MarketplaceVendorOrder $vendor): bool => $vendor->status === 'delivered')) {
                throw new ConflictHttpException('Returns are available after delivery is completed.');
            }
            if (MarketplaceReturnRequest::where('marketplace_order_id', $lockedOrder->id)->where('customer_user_id', $customer->id)->exists()) {
                throw new ConflictHttpException('A return request already exists for this order.');
            }

            $requested = [];
            $requestedAmount = '0.00';
            foreach ($items as $item) {
                $orderItem = $lockedOrder->items->firstWhere('uuid', $item['item_id']);
                if (! $orderItem) {
                    throw new UnprocessableEntityHttpException('A requested return item does not belong to this order.');
                }
                $quantity = bcadd((string) $item['quantity'], '0', 3);
                if (bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, (string) $orderItem->quantity, 3) > 0) {
                    throw new UnprocessableEntityHttpException('The requested return quantity is invalid.');
                }
                $amount = bcmul((string) $orderItem->unit_price, $quantity, 2);
                $requestedAmount = bcadd($requestedAmount, $amount, 2);
                $requested[] = ['item_id' => $orderItem->uuid, 'quantity' => $quantity, 'name' => $orderItem->product_name_snapshot, 'sku' => $orderItem->sku_snapshot, 'amount' => $amount];
            }

            $return = MarketplaceReturnRequest::create(['marketplace_order_id' => $lockedOrder->id, 'customer_user_id' => $customer->id, 'requested_items' => $requested, 'reason' => $reason, 'status' => 'pending']);
            MarketplaceRefundRequest::create(['marketplace_order_id' => $lockedOrder->id, 'customer_user_id' => $customer->id, 'marketplace_return_request_id' => $return->id, 'requested_amount' => $requestedAmount, 'currency_code' => $lockedOrder->currency_code, 'reason' => $reason, 'status' => 'pending']);
            $lockedOrder->events()->create(['actor_user_id' => $customer->id, 'event' => 'return_requested', 'metadata' => ['request_id' => $return->uuid, 'requested_amount' => $requestedAmount]]);

            return $return->load('order');
        });
    }

    public function decideCancellation(MarketplaceCancellationRequest $request, User $actor, string $decision, ?string $note = null): MarketplaceCancellationRequest
    {
        return DB::transaction(function () use ($request, $actor, $decision, $note): MarketplaceCancellationRequest {
            $locked = MarketplaceCancellationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new ConflictHttpException('This cancellation request has already been decided.');
            }
            $order = MarketplaceOrder::whereKey($locked->marketplace_order_id)->lockForUpdate()->with(['payments', 'vendorOrders.shipments'])->firstOrFail();
            if ($decision === 'approved') {
                if ($order->payments->contains(fn ($payment): bool => $payment->status === 'paid')) {
                    throw new ConflictHttpException('A verified provider refund is required before cancelling a paid order.');
                }
                foreach ($order->vendorOrders as $vendorOrder) {
                    foreach ($vendorOrder->shipments as $shipment) {
                        if (in_array($shipment->status, ['shipped', 'in_transit', 'delivered', 'returned'], true)) {
                            throw new ConflictHttpException('A shipped vendor order cannot be cancelled.');
                        }
                        if ($shipment->status === 'ready') {
                            $this->fulfillment->transitionShipment($shipment, $actor, 'cancelled', 'Shipment cancelled with the approved order cancellation.');
                        }
                    }
                    $this->reservations->releaseForVendorOrder($vendorOrder);
                    if ($vendorOrder->status !== 'cancelled') {
                        $from = $vendorOrder->status;
                        $vendorOrder->update(['status' => 'cancelled']);
                        $order->events()->create(['marketplace_vendor_order_id' => $vendorOrder->id, 'actor_user_id' => $actor->id, 'event' => 'vendor_order_cancelled', 'from_status' => $from, 'to_status' => 'cancelled']);
                    }
                }
                $order->update(['status' => 'cancelled', 'payment_status' => $order->payment_status === 'pending' ? 'cancelled' : $order->payment_status]);
                $order->events()->create(['actor_user_id' => $actor->id, 'event' => 'cancellation_approved', 'from_status' => 'confirmed', 'to_status' => 'cancelled', 'metadata' => ['request_id' => $locked->uuid]]);
            }
            $locked->update(['status' => $decision, 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now()]);

            return $locked->refresh()->load('order');
        });
    }

    public function decideReturn(MarketplaceReturnRequest $request, User $actor, string $decision, ?string $note = null, ?string $approvedAmount = null): MarketplaceReturnRequest
    {
        return DB::transaction(function () use ($request, $actor, $decision, $note, $approvedAmount): MarketplaceReturnRequest {
            $locked = MarketplaceReturnRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new ConflictHttpException('This return request has already been decided.');
            }
            $order = MarketplaceOrder::whereKey($locked->marketplace_order_id)->lockForUpdate()->firstOrFail();
            $refund = MarketplaceRefundRequest::where('marketplace_return_request_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($decision === 'approved') {
                $amount = $approvedAmount === null ? (string) $refund->requested_amount : bcadd($approvedAmount, '0', 2);
                if (bccomp($amount, '0.00', 2) < 0 || bccomp($amount, (string) $order->grand_total, 2) > 0) {
                    throw new UnprocessableEntityHttpException('The approved refund amount is invalid.');
                }
                $refund->update(['approved_amount' => $amount, 'status' => 'blocked', 'review_note' => 'Real-money refund execution is disabled until a verified payment provider is configured.', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                $order->events()->create(['actor_user_id' => $actor->id, 'event' => 'return_approved_refund_blocked', 'metadata' => ['request_id' => $locked->uuid, 'approved_amount' => $amount]]);
            } else {
                $refund->update(['status' => 'rejected', 'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            }
            $locked->update(['status' => $decision, 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now()]);

            return $locked->refresh()->load('order');
        });
    }

    private function assertCustomer(MarketplaceOrder $order, User $customer): void
    {
        if ((int) $order->customer_user_id !== (int) $customer->id) {
            abort(404);
        }
    }
}
