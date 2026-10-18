<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosPaymentService
{
    public function __construct(private readonly PosAccessService $access, private readonly PosSaleService $sales, private readonly AuditLogger $audit) {}

    public function add(Sale $sale, array $payload, User $actor, string $idempotencyKey): Payment
    {
        return DB::transaction(function () use ($sale, $payload, $actor, $idempotencyKey): Payment {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->with('store')->firstOrFail();
            if (! $this->access->canOperate($actor, $sale->store)) {
                throw new AccessDeniedHttpException('You cannot accept payments in this store.');
            }
            $hash = hash('sha256', json_encode(['sale_id' => $sale->uuid, 'method' => $payload['method'], 'amount' => $payload['amount'], 'external_reference' => $payload['external_reference'] ?? null, 'provider' => $payload['provider'] ?? null], JSON_THROW_ON_ERROR));
            $existing = Payment::where('tenant_id', $sale->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new ConflictHttpException('This idempotency key was already used with a different payment request.');
                }

                return $existing;
            }
            if (! in_array($sale->status, ['draft', 'held', 'pending_payment'], true)) {
                throw new ConflictHttpException('Payments cannot be added to this sale.');
            }
            $verified = (string) Payment::where('sale_id', $sale->id)->where('status', 'verified')->sum('amount');
            if (bccomp($verified, (string) $sale->grand_total, 2) >= 0) {
                throw new ConflictHttpException('This sale is already fully paid.');
            }
            $amount = (string) $payload['amount'];
            if ($payload['method'] !== 'cash' && bccomp(bcadd($verified, $amount, 2), (string) $sale->grand_total, 2) > 0) {
                throw new UnprocessableEntityHttpException('Non-cash payment cannot exceed the outstanding sale balance.');
            }
            if ($payload['method'] === 'card_terminal' && (empty($payload['external_reference']) || empty($payload['provider']))) {
                throw new UnprocessableEntityHttpException('Card terminal payments require a provider and terminal reference.');
            }
            if (! empty($payload['external_reference']) && ! empty($payload['provider']) && Payment::where('tenant_id', $sale->tenant_id)->where('provider', $payload['provider'])->where('external_reference', $payload['external_reference'])->exists()) {
                throw new ConflictHttpException('This external payment reference has already been recorded.');
            }
            $payment = Payment::create(['tenant_id' => $sale->tenant_id, 'store_id' => $sale->store_id, 'sale_id' => $sale->id, 'method' => $payload['method'], 'amount' => $amount, 'currency_code' => $sale->currency_code, 'status' => $payload['method'] === 'cash' ? 'verified' : 'pending', 'external_reference' => $payload['external_reference'] ?? null, 'provider' => $payload['provider'] ?? null, 'verified_at' => $payload['method'] === 'cash' ? now() : null, 'collected_by' => $actor->id, 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash, 'metadata' => $payload['metadata'] ?? null]);
            $this->sales->refreshPaymentTotals($sale);
            $this->audit->record('pos.payment_created', $actor, $sale->store->tenant, $payment, ['sale_id' => $sale->uuid, 'method' => $payment->method, 'status' => $payment->status, 'amount' => $payment->amount]);

            return $payment;
        });
    }

    public function verify(Payment $payment, array $payload, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $payload, $actor): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->with(['sale', 'store'])->firstOrFail();
            if (! $this->access->canManage($actor, $payment->store)) {
                throw new AccessDeniedHttpException('Only an authorized manager can verify external payments.');
            }
            if ($payment->status === 'verified') {
                return $payment;
            }
            if ($payment->status !== 'pending' || $payment->method === 'cash') {
                throw new ConflictHttpException('This payment is not awaiting external verification.');
            }
            if (! config('pos.allow_manual_payment_verification', false)) {
                throw new AccessDeniedHttpException('Manual payment verification is disabled; configure a trusted provider callback or explicitly enable audited development reconciliation.');
            }
            $duplicate = Payment::where('tenant_id', $payment->tenant_id)->where('provider', $payload['provider'])->where('external_reference', $payload['external_reference'])->where('id', '!=', $payment->id)->exists();
            if ($duplicate) {
                throw new ConflictHttpException('This provider payment reference is already attached to another payment.');
            }
            $payment->update(['external_reference' => $payload['external_reference'], 'provider' => $payload['provider'], 'status' => 'verified', 'verified_at' => now(), 'metadata' => array_merge($payment->metadata ?? [], ['verification_note' => $payload['verification_note'], 'verification_mode' => 'manual_authorized'])]);
            $this->sales->refreshPaymentTotals($payment->sale);
            $this->audit->record('pos.payment_verified', $actor, $payment->store->tenant, $payment, ['sale_id' => $payment->sale->uuid, 'provider' => $payment->provider, 'external_reference' => $payment->external_reference]);

            return $payment->fresh();
        });
    }
}
