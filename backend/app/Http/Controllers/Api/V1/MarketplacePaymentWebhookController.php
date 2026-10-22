<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\MarketplaceCommissionService;
use App\Services\MarketplaceDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplacePaymentWebhookController
{
    public function __invoke(Request $request, string $provider, MarketplaceCommissionService $commissions, MarketplaceDocumentService $documents): JsonResponse
    {
        $secret = (string) config('marketplace.webhook_secret');
        $signature = (string) $request->header('X-Payment-Signature');
        $raw = $request->getContent();
        if ($secret === '' || $signature === '' || ! hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
            throw new UnauthorizedHttpException('Payment provider signature verification failed.');
        }

        $data = $request->json()->all();
        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, ['paid', 'failed', 'cancelled', 'refunded'], true)) {
            throw new UnprocessableEntityHttpException('Unsupported payment callback status.');
        }
        $reference = (string) ($data['payment_reference'] ?? $data['provider_reference'] ?? '');
        if ($reference === '') {
            throw new UnprocessableEntityHttpException('The payment callback has no provider reference.');
        }

        $result = DB::transaction(function () use ($commissions, $documents, $data, $provider, $reference, $status): array {
            $payment = \App\Models\MarketplacePayment::query()->where('provider_reference', $reference)->lockForUpdate()->first();
            if (! $payment || ($payment->provider !== null && $payment->provider !== $provider)) {
                throw new ConflictHttpException('The payment reference is not recognized.');
            }
            $order = $payment->order()->lockForUpdate()->firstOrFail();
            if (isset($data['amount']) && bccomp((string) $data['amount'], (string) $payment->amount, 2) !== 0) {
                throw new UnprocessableEntityHttpException('The callback amount does not match the order payment.');
            }
            if (isset($data['currency_code']) && strtoupper((string) $data['currency_code']) !== strtoupper((string) $payment->currency_code)) {
                throw new UnprocessableEntityHttpException('The callback currency does not match the order payment.');
            }
            if ($payment->status === $status) {
                return ['payment' => $payment, 'order' => $order, 'replayed' => true];
            }
            if (in_array($payment->status, ['paid', 'refunded'], true)) {
                throw new ConflictHttpException('A settled payment cannot transition to another state.');
            }

            $payment->update([
                'provider' => $provider,
                'status' => $status,
                'amount_received' => $status === 'paid' ? $payment->amount : $payment->amount_received,
                'received_at' => $status === 'paid' ? now() : $payment->received_at,
                'paid_at' => $status === 'paid' ? now() : null,
                'metadata' => array_merge((array) $payment->metadata, ['webhook_status' => $status, 'webhook_event_id' => $data['event_id'] ?? null]),
            ]);
            if ($status === 'paid') {
                $documents->ensureReceiptNumber($payment->refresh());
            }
            $payments = $order->payments()->lockForUpdate()->get();
            $nextOrderStatus = $payments->contains(fn ($item): bool => $item->status === 'paid') ? 'paid' : ($payments->every(fn ($item): bool => in_array($item->status, ['failed', 'cancelled'], true)) ? 'failed' : 'pending');
            $order->update(['payment_status' => $nextOrderStatus]);
            $order->events()->create(['event' => 'payment_webhook_processed', 'from_status' => $payment->status, 'to_status' => $status, 'metadata' => ['provider' => $provider, 'provider_reference' => $reference, 'event_id' => $data['event_id'] ?? null]]);
            if ($status === 'paid') {
                $commissions->markOrderPayable($order);
            }

            return ['payment' => $payment->fresh(), 'order' => $order->fresh(), 'replayed' => false];
        });

        return response()->json(['success' => true, 'idempotent_replay' => $result['replayed'], 'data' => ['payment_reference' => $result['payment']->provider_reference, 'status' => $result['payment']->status, 'order_id' => $result['order']->uuid, 'payment_status' => $result['order']->payment_status]]);
    }
}
