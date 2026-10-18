<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SaleReturnService
{
    public function __construct(private readonly PosAccessService $access, private readonly AuditLogger $audit) {}

    public function create(Sale $sale, array $payload, User $actor, string $idempotencyKey): SaleReturn
    {
        return DB::transaction(function () use ($sale, $payload, $actor, $idempotencyKey): SaleReturn {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->with('store')->firstOrFail();
            if (! $this->access->canOperate($actor, $sale->store)) {
                throw new AccessDeniedHttpException('You cannot request returns for this store.');
            }
            if (! in_array($sale->status, ['completed', 'partially_refunded'], true)) {
                throw new ConflictHttpException('Only completed sales can be returned.');
            }
            $hash = hash('sha256', json_encode(['sale_id' => $sale->uuid, 'reason' => $payload['reason'], 'items' => $payload['items']], JSON_THROW_ON_ERROR));
            $existing = SaleReturn::where('tenant_id', $sale->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new ConflictHttpException('This idempotency key was already used with a different return request.');
                }

                return $existing->load(['items.saleItem', 'items.variant', 'sale', 'store']);
            }
            $saleItems = SaleItem::where('tenant_id', $sale->tenant_id)->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('uuid');
            $requested = [];
            foreach ($payload['items'] as $input) {
                $item = $saleItems->get($input['sale_item_id']);
                if (! $item) {
                    throw new UnprocessableEntityHttpException('A return item does not belong to the original sale.');
                }
                if (isset($requested[$item->id])) {
                    throw new UnprocessableEntityHttpException('Each sale item may appear only once in a return request.');
                }
                $quantity = $this->quantity((string) $input['quantity']);
                $alreadyReserved = (string) DB::table('sale_return_items')->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')->where('sale_return_items.sale_item_id', $item->id)->whereIn('sale_returns.status', ['requested', 'approved'])->sum('sale_return_items.quantity');
                $available = BigDecimal::of((string) $item->quantity)->minus((string) $item->returned_quantity)->minus($alreadyReserved)->toScale(3, RoundingMode::HalfUp)->__toString();
                if (bccomp($quantity, $available, 3) > 0) {
                    throw new UnprocessableEntityHttpException('The requested return quantity exceeds the refundable quantity.');
                }
                if ((bool) $input['restock'] && $input['disposition'] !== 'sellable') {
                    throw new UnprocessableEntityHttpException('Only sellable returned items may be restocked.');
                }
                $unitRefund = $this->money(BigDecimal::of((string) $item->line_total)->dividedBy((string) $item->quantity, 8, RoundingMode::HalfUp)->__toString());
                $amount = $this->money(BigDecimal::of($unitRefund)->multipliedBy($quantity)->__toString());
                $requested[$item->id] = ['item' => $item, 'quantity' => $quantity, 'unit_refund_amount' => $unitRefund, 'refund_amount' => $amount, 'restock' => (bool) $input['restock'], 'disposition' => $input['disposition']];
            }
            $total = '0.00';
            foreach ($requested as $line) {
                $total = $this->money(BigDecimal::of($total)->plus($line['refund_amount'])->__toString());
            }
            $return = SaleReturn::create(['tenant_id' => $sale->tenant_id, 'store_id' => $sale->store_id, 'sale_id' => $sale->id, 'return_number' => $this->nextNumber($sale), 'status' => 'requested', 'reason' => $payload['reason'], 'refund_total' => $total, 'requested_by' => $actor->id, 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash, 'requested_at' => now()]);
            foreach ($requested as $line) {
                $return->items()->create(['tenant_id' => $sale->tenant_id, 'sale_item_id' => $line['item']->id, 'product_variant_id' => $line['item']->product_variant_id, 'quantity' => $line['quantity'], 'unit_refund_amount' => $line['unit_refund_amount'], 'refund_amount' => $line['refund_amount'], 'restock' => $line['restock'], 'disposition' => $line['disposition']]);
            }
            $this->audit->record('pos.return_requested', $actor, $sale->store->tenant, $return, ['sale_id' => $sale->uuid, 'refund_total' => $total]);

            return $return->load(['items.saleItem', 'items.variant', 'sale', 'store']);
        });
    }

    public function approve(SaleReturn $return, User $actor): SaleReturn
    {
        return DB::transaction(function () use ($return, $actor): SaleReturn {
            $return = SaleReturn::whereKey($return->id)->lockForUpdate()->with(['store', 'sale'])->firstOrFail();
            if (! $this->access->canManage($actor, $return->store)) {
                throw new AccessDeniedHttpException('Only a store manager can approve returns.');
            }
            if ($return->status !== 'requested') {
                throw new ConflictHttpException('Only requested returns can be approved.');
            }
            $return->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record('pos.return_approved', $actor, $return->store->tenant, $return);

            return $return->fresh(['items.saleItem', 'items.variant', 'sale', 'store']);
        });
    }

    private function nextNumber(Sale $sale): string
    {
        do {
            $number = 'RET-'.now($sale->store?->timezone ?: 'UTC')->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (SaleReturn::where('tenant_id', $sale->tenant_id)->where('return_number', $number)->exists());

        return $number;
    }

    private function quantity(string $value): string
    {
        return BigDecimal::of($value)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    private function money(string $value): string
    {
        return BigDecimal::of($value)->toScale(2, RoundingMode::HalfUp)->__toString();
    }
}
