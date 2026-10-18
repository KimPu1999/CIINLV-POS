<?php

namespace App\Services;

use App\Events\SaleRefunded;
use App\Jobs\RefreshReportingCache;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RefundService
{
    public function __construct(private readonly PosAccessService $access, private readonly InventoryService $inventory, private readonly AuditLogger $audit) {}

    public function process(SaleReturn $return, array $payload, User $actor, string $idempotencyKey): Refund
    {
        $refund = DB::transaction(function () use ($return, $payload, $actor, $idempotencyKey): Refund {
            $return = SaleReturn::whereKey($return->id)->lockForUpdate()->with(['sale', 'store', 'items.variant'])->firstOrFail();
            if (! $this->access->canManage($actor, $return->store)) {
                throw new AccessDeniedHttpException('Only an authorized manager can process refunds.');
            }
            $existing = Refund::where('tenant_id', $return->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing->load(['return', 'payment']);
            }
            $existingReturnRefund = Refund::where('sale_return_id', $return->id)->first();
            if ($existingReturnRefund) {
                throw new ConflictHttpException('This return already has a refund record.');
            }
            if ($return->status !== 'approved') {
                throw new ConflictHttpException('Only approved returns can be refunded.');
            }
            $sale = Sale::whereKey($return->sale_id)->lockForUpdate()->with('payments')->firstOrFail();
            $payment = $payload['payment_id'] ?? null ? Payment::where('tenant_id', $return->tenant_id)->where('uuid', $payload['payment_id'])->where('sale_id', $sale->id)->firstOrFail() : $sale->payments->where('status', 'verified')->sortByDesc('created_at')->first();
            $method = $payload['method'] ?? $payment?->method ?? 'cash';
            if ($payment && $method !== $payment->method) {
                throw new UnprocessableEntityHttpException('The refund method must match the selected original payment.');
            }
            $isCash = $method === 'cash';
            if (! $isCash && (empty($payload['provider']) || empty($payload['external_reference']))) {
                throw new UnprocessableEntityHttpException('External refunds require a provider and refund reference.');
            }
            $refund = Refund::create(['tenant_id' => $return->tenant_id, 'store_id' => $return->store_id, 'sale_id' => $sale->id, 'sale_return_id' => $return->id, 'payment_id' => $payment?->id, 'method' => $method, 'amount' => $return->refund_total, 'currency_code' => $sale->currency_code, 'status' => $isCash ? 'succeeded' : 'pending', 'external_reference' => $payload['external_reference'] ?? null, 'provider' => $payload['provider'] ?? null, 'processed_by' => $actor->id, 'idempotency_key' => $idempotencyKey, 'processed_at' => $isCash ? now() : null]);
            if (! $isCash) {
                $this->audit->record('pos.refund_pending_external', $actor, $return->store->tenant, $refund, ['return_id' => $return->uuid, 'provider' => $refund->provider]);

                return $refund->load(['return', 'payment']);
            }
            $location = InventoryLocation::where('tenant_id', $return->tenant_id)->where('store_id', $return->store_id)->where('location_type', 'store')->where('status', 'active')->first();
            if (! $location) {
                throw new UnprocessableEntityHttpException('This store has no active inventory location.');
            }
            foreach ($return->items->sortBy('product_variant_id') as $item) {
                if ($item->restock) {
                    $this->inventory->mutate($location, $item->variant, 'sale_return', (string) $item->quantity, $actor, 'sale_return', $return->uuid, 'Sellable customer return', 'return:'.$return->uuid.':'.$item->uuid, (string) $item->variant->purchase_cost);
                }
                $item->saleItem()->update(['returned_quantity' => bcadd((string) $item->saleItem->returned_quantity, (string) $item->quantity, 3)]);
            }
            $shift = CashierShift::where('tenant_id', $return->tenant_id)->where('store_id', $return->store_id)->where('register_id', $sale->register_id)->where('status', 'open')->lockForUpdate()->first();
            if (! $shift) {
                throw new ConflictHttpException('An open register shift is required for a cash refund.');
            }
            CashMovement::firstOrCreate(['tenant_id' => $return->tenant_id, 'idempotency_key' => 'refund:'.$return->uuid.':cash'], ['store_id' => $return->store_id, 'register_id' => $sale->register_id, 'cashier_shift_id' => $shift->id, 'movement_type' => 'cash_refund', 'amount' => $refund->amount, 'reference_type' => 'refund', 'reference_id' => $refund->uuid, 'created_by' => $actor->id]);
            $newRefunded = bcadd((string) $sale->refunded_total, (string) $refund->amount, 2);
            $sale->update(['refunded_total' => $newRefunded, 'status' => bccomp($newRefunded, (string) $sale->grand_total, 2) >= 0 ? 'refunded' : 'partially_refunded', 'payment_status' => bccomp($newRefunded, (string) $sale->grand_total, 2) >= 0 ? 'refunded' : 'partially_refunded']);
            $return->update(['status' => 'refunded', 'refunded_at' => now()]);
            $this->audit->record('pos.refund_succeeded', $actor, $return->store->tenant, $refund, ['return_id' => $return->uuid, 'amount' => $refund->amount]);

            return $refund->fresh(['return', 'payment']);
        });
        if ($refund->status === 'succeeded') {
            event(new SaleRefunded($refund->loadMissing(['tenant', 'store', 'sale'])));
            RefreshReportingCache::dispatch((int) $refund->tenant_id)->afterCommit();
        }

        return $refund;
    }
}
