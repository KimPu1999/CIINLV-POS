<?php

namespace App\Services;

use App\Models\MarketplaceOrder;
use App\Models\MarketplaceVendorOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplacePaymentService
{
    public function __construct(
        private readonly MarketplaceCommissionService $commissions,
        private readonly MarketplaceDocumentService $documents,
        private readonly BusinessNotificationService $notifications,
    ) {}

    public function collectCod(MarketplaceOrder $order, User $actor, array $data, ?MarketplaceVendorOrder $vendorOrder = null): array
    {
        $result = DB::transaction(function () use ($order, $actor, $data, $vendorOrder): array {
            $lockedOrder = MarketplaceOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $payment = $lockedOrder->payments()->where('method', 'cod')->lockForUpdate()->firstOrFail();
            $metadata = (array) $payment->metadata;
            $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
            $replays = (array) ($metadata['collection_idempotency_keys'] ?? []);
            if ($idempotencyKey !== '' && array_key_exists($idempotencyKey, $replays)) {
                return ['order' => $lockedOrder->fresh(), 'payment' => $payment->fresh(), 'replayed' => true, 'vendor_order' => $vendorOrder];
            }
            if ($payment->status === 'paid') {
                return ['order' => $lockedOrder->fresh(), 'payment' => $payment->fresh(), 'replayed' => true, 'vendor_order' => $vendorOrder];
            }
            if ($payment->status !== 'pending') {
                throw new ConflictHttpException('This COD payment is not available for collection.');
            }
            if ($vendorOrder) {
                $this->assertVendorFulfillmentActive($lockedOrder, $vendorOrder);
            }

            $scopeTotal = (string) ($vendorOrder?->total ?: $lockedOrder->grand_total);
            $collections = (array) ($metadata['vendor_collections'] ?? []);
            $scopeKey = $vendorOrder?->uuid;
            $scopeReceived = $scopeKey ? (string) ($collections[$scopeKey] ?? '0') : (string) ($payment->amount_received ?: '0');
            $due = bcsub($scopeTotal, $scopeReceived, 2);
            if (bccomp($due, '0', 2) <= 0) {
                throw new ConflictHttpException('This COD amount has already been collected.');
            }
            $amount = $data['amount_received'] ?? $due;
            if (! is_numeric($amount) || bccomp((string) $amount, '0', 2) <= 0) {
                throw new UnprocessableEntityHttpException('The received amount must be greater than zero.');
            }
            if (bccomp((string) $amount, $due, 2) > 0) {
                throw new UnprocessableEntityHttpException('The received amount cannot exceed the balance due.');
            }

            $nextScopeReceived = $this->money(bcadd($scopeReceived, (string) $amount, 2));
            $aggregateReceived = $scopeKey
                ? $this->money(bcadd((string) ($payment->amount_received ?: '0'), (string) $amount, 2))
                : $nextScopeReceived;
            $fullyPaid = bccomp($aggregateReceived, (string) $lockedOrder->grand_total, 2) >= 0;
            if ($scopeKey) {
                $collections[$scopeKey] = $nextScopeReceived;
            }
            if ($idempotencyKey !== '') {
                $replays[$idempotencyKey] = ['amount' => $amount, 'recorded_at' => now()->toIso8601String()];
            }
            $metadata['vendor_collections'] = $collections;
            $metadata['collection_idempotency_keys'] = $replays;
            $metadata['collected_by'] = $actor->uuid;
            $metadata['verification_note'] = $data['verification_note'] ?? $data['note'] ?? null;

            $paymentData = [
                'tenant_id' => $payment->tenant_id ?: $vendorOrder?->tenant_id,
                'marketplace_vendor_order_id' => $payment->marketplace_vendor_order_id ?: $vendorOrder?->id,
                'marketplace_store_id' => $payment->marketplace_store_id ?: $vendorOrder?->marketplace_store_id,
                'amount_received' => $aggregateReceived,
                'change_amount' => '0.00',
                'transaction_reference' => $data['transaction_reference'] ?? $payment->transaction_reference,
                'received_by' => $actor->id,
                'received_at' => $data['received_at'] ?? now(),
                'status' => $fullyPaid ? 'paid' : 'pending',
                'paid_at' => $fullyPaid ? ($payment->paid_at ?: ($data['received_at'] ?? now())) : $payment->paid_at,
                'metadata' => $metadata,
            ];
            $payment->update($paymentData);
            if ($fullyPaid) {
                $payment->refresh();
                $this->documents->ensureReceiptNumber($payment);
                $lockedOrder->update(['payment_status' => 'paid']);
                $this->commissions->markOrderPayable($lockedOrder);
            }
            $lockedOrder->events()->create([
                'actor_user_id' => $actor->id,
                'event' => 'payment_received',
                'from_status' => 'pending',
                'to_status' => $fullyPaid ? 'paid' : 'pending',
                'metadata' => ['amount_received' => $amount, 'transaction_reference' => $data['transaction_reference'] ?? null, 'vendor_order_id' => $vendorOrder?->uuid],
            ]);

            return ['order' => $lockedOrder->fresh(), 'payment' => $payment->fresh(), 'replayed' => false, 'vendor_order' => $vendorOrder];
        });

        if (! $result['replayed'] && $result['payment']->status === 'paid') {
            $this->notifications->paymentReceived($result['order'], null, $result['payment']);
        }

        return $result;
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    private function assertVendorFulfillmentActive(MarketplaceOrder $order, MarketplaceVendorOrder $vendorOrder): void
    {
        if (! in_array($vendorOrder->fulfillment_status, ['active', 'shipped', 'in_transit'], true)) {
            throw new UnprocessableEntityHttpException('Fulfillment must be active before receiving cash payment.');
        }

        if (! in_array($order->status, ['confirmed', 'processing', 'partially_shipped', 'shipped'], true)) {
            throw new UnprocessableEntityHttpException('The order must be confirmed before receiving payment.');
        }
    }
}
