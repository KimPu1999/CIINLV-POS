<?php

namespace App\Services;

use App\Events\SaleCompleted;
use App\Jobs\RefreshReportingCache;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosSaleService
{
    public function __construct(
        private readonly PosAccessService $access,
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
        private readonly ReceiptService $receipts,
    ) {}

    public function preview(Store $store, array $payload, User $actor): array
    {
        $this->assertStoreAccess($store, $actor);

        return $this->calculate($store, $payload, $actor);
    }

    public function createDraft(Store $store, PosRegister $register, array $payload, User $actor, string $idempotencyKey): Sale
    {
        return DB::transaction(function () use ($store, $register, $payload, $actor, $idempotencyKey): Sale {
            $this->assertRegisterAccess($store, $register, $actor);
            $hash = $this->hashPayload($payload, $store, $register);
            $existing = Sale::where('tenant_id', $store->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new ConflictHttpException('This idempotency key was already used with a different sale request.');
                }

                return $existing->load(['items.variant', 'payments', 'store', 'register']);
            }
            $this->assertOpenShift($store, $register, $actor);
            $calculation = $this->calculate($store, $payload, $actor);
            $sale = Sale::create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'register_id' => $register->id,
                'cashier_user_id' => $actor->id,
                'customer_id' => $payload['customer_id'] ?? null,
                'sale_number' => $this->nextNumber($store, 'SALE'),
                'subtotal' => $calculation['subtotal'],
                'discount_total' => $calculation['discount_total'],
                'taxable_amount' => $calculation['taxable_amount'],
                'tax_total' => $calculation['tax_total'],
                'service_charge_total' => $calculation['service_charge_total'],
                'fee_total' => $calculation['fee_total'],
                'rounding_adjustment' => $calculation['rounding_adjustment'],
                'grand_total' => $calculation['grand_total'],
                'balance_due' => $calculation['grand_total'],
                'currency_code' => $calculation['currency_code'],
                'status' => 'draft',
                'payment_status' => 'unpaid',
                'tax_breakdown' => $calculation['tax_breakdown'],
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
            ]);
            foreach ($calculation['items'] as $item) {
                $sale->items()->create($item);
            }
            if (bccomp($calculation['discount_total'], '0', 2) > 0) {
                $sale->adjustments()->create(['tenant_id' => $store->tenant_id, 'type' => 'discount', 'label' => $payload['discount_label'] ?? 'Discount', 'amount' => $calculation['discount_total'], 'metadata' => ['item_discount_total' => $calculation['item_discount_total'], 'cart_discount_total' => $calculation['cart_discount_total']], 'created_by' => $actor->id]);
            }
            if (bccomp($calculation['service_charge_total'], '0', 2) !== 0) {
                $sale->adjustments()->create(['tenant_id' => $store->tenant_id, 'type' => 'surcharge', 'label' => 'Service charge', 'amount' => $calculation['service_charge_total'], 'metadata' => ['kind' => 'service_charge'], 'created_by' => $actor->id]);
            }
            foreach ($calculation['fees'] as $fee) {
                if (bccomp($fee['amount'], '0', 2) !== 0) {
                    $sale->adjustments()->create(['tenant_id' => $store->tenant_id, 'type' => 'surcharge', 'label' => $fee['label'], 'amount' => $fee['amount'], 'metadata' => ['kind' => 'configured_fee', 'code' => $fee['code'] ?? null], 'created_by' => $actor->id]);
                }
            }
            if (bccomp($calculation['rounding_adjustment'], '0', 2) !== 0) {
                $sale->adjustments()->create(['tenant_id' => $store->tenant_id, 'type' => 'surcharge', 'label' => 'Rounding adjustment', 'amount' => $calculation['rounding_adjustment'], 'metadata' => ['kind' => 'rounding'], 'created_by' => $actor->id]);
            }
            $this->audit->record('pos.sale_created', $actor, $store->tenant, $sale, ['store_id' => $store->uuid, 'sale_number' => $sale->sale_number]);

            return $sale->load(['items.variant', 'payments', 'store', 'register']);
        });
    }

    public function complete(Sale $sale, User $actor): Sale
    {
        $completed = DB::transaction(function () use ($sale, $actor): Sale {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->with(['items.variant.product', 'store', 'register'])->firstOrFail();
            if ($sale->status === 'completed' || $sale->status === 'partially_refunded' || $sale->status === 'refunded') {
                return $sale->load(['items.variant', 'payments', 'store', 'register', 'receipt']);
            }
            if (! in_array($sale->status, ['draft', 'held', 'pending_payment'], true)) {
                throw new ConflictHttpException('This sale cannot be completed in its current state.');
            }
            if (! $this->access->canOperate($actor, $sale->store)) {
                throw new AccessDeniedHttpException('You cannot complete sales in this store.');
            }
            $shift = CashierShift::where('tenant_id', $sale->tenant_id)->where('store_id', $sale->store_id)->where('register_id', $sale->register_id)->where('cashier_user_id', $sale->cashier_user_id)->where('status', 'open')->lockForUpdate()->first();
            if (! $shift) {
                throw new ConflictHttpException('The cashier shift must be open before completing this sale.');
            }
            $verifiedPayments = Payment::where('sale_id', $sale->id)->where('status', 'verified')->lockForUpdate()->get();
            $verified = $verifiedPayments->reduce(fn (string $carry, Payment $payment): string => $this->add($carry, (string) $payment->amount), '0.00');
            if (bccomp($verified, (string) $sale->grand_total, 2) < 0) {
                $sale->update(['paid_total' => $verified, 'balance_due' => $this->subtractPositive((string) $sale->grand_total, $verified), 'payment_status' => $verified === '0.00' ? 'pending' : 'partially_paid', 'status' => 'pending_payment']);
                throw new UnprocessableEntityHttpException('The sale does not have enough verified payment to complete.');
            }
            $location = InventoryLocation::where('tenant_id', $sale->tenant_id)->where('store_id', $sale->store_id)->where('location_type', 'store')->where('status', 'active')->first();
            if (! $location) {
                throw new UnprocessableEntityHttpException('This store has no active inventory location.');
            }
            foreach ($sale->items->sortBy('product_variant_id') as $item) {
                if (! $item->variant->product->track_inventory) {
                    continue;
                }
                $balance = InventoryBalance::where('tenant_id', $sale->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
                if ($balance) {
                    $item->update(['unit_cost_at_sale' => $balance->average_cost]);
                }
                $this->inventory->mutate($location, $item->variant, 'sale', '-'.$item->quantity, $actor, 'sale', $sale->uuid, null, 'sale:'.$sale->uuid.':'.$item->uuid);
            }
            $change = $this->subtractPositive($verified, (string) $sale->grand_total);
            $cashTendered = $verifiedPayments->where('method', 'cash')->reduce(fn (string $carry, Payment $payment): string => $this->add($carry, (string) $payment->amount), '0.00');
            if (bccomp($cashTendered, '0.00', 2) > 0) {
                CashMovement::firstOrCreate(['tenant_id' => $sale->tenant_id, 'idempotency_key' => 'sale:'.$sale->uuid.':cash'], ['store_id' => $sale->store_id, 'register_id' => $sale->register_id, 'cashier_shift_id' => $shift->id, 'movement_type' => 'cash_sale', 'amount' => $this->subtractPositive($cashTendered, $change), 'reference_type' => 'sale', 'reference_id' => $sale->uuid, 'created_by' => $actor->id]);
            }
            $sale->update(['paid_total' => $verified, 'balance_due' => '0.00', 'change_due' => $change, 'payment_status' => 'paid', 'status' => 'completed', 'completed_at' => now()]);
            $this->receipts->ensure($sale, $actor);
            $this->audit->record('pos.sale_completed', $actor, $sale->store->tenant, $sale, ['sale_number' => $sale->sale_number, 'grand_total' => $sale->grand_total]);

            return $sale->fresh(['items.variant', 'payments', 'store', 'register', 'receipt']);
        });
        if ($completed->status === 'completed') {
            event(new SaleCompleted($completed->loadMissing(['tenant', 'store'])));
            RefreshReportingCache::dispatch((int) $completed->tenant_id)->afterCommit();
        }

        return $completed;
    }

    public function hold(Sale $sale, User $actor): Sale
    {
        if (! $this->holdResumeEnabled($sale->store)) {
            throw new ConflictHttpException('Hold and resume is disabled for this business.');
        }
        if ($sale->status !== 'draft') {
            throw new ConflictHttpException('Only draft sales can be held.');
        }
        $sale->update(['status' => 'held', 'held_at' => now()]);
        $this->audit->record('pos.sale_held', $actor, $sale->store->tenant, $sale);

        return $sale->fresh(['items.variant', 'payments', 'store', 'register']);
    }

    public function resume(Sale $sale, User $actor): Sale
    {
        if (! $this->holdResumeEnabled($sale->store)) {
            throw new ConflictHttpException('Hold and resume is disabled for this business.');
        }
        if ($sale->status !== 'held') {
            throw new ConflictHttpException('Only held sales can be resumed.');
        }
        $sale->update(['status' => 'draft', 'held_at' => null]);
        $this->audit->record('pos.sale_resumed', $actor, $sale->store->tenant, $sale);

        return $sale->fresh(['items.variant', 'payments', 'store', 'register']);
    }

    public function cancel(Sale $sale, User $actor): Sale
    {
        if (in_array($sale->status, ['completed', 'partially_refunded', 'refunded', 'cancelled'], true)) {
            throw new ConflictHttpException('This sale cannot be cancelled.');
        }
        $sale->update(['status' => 'cancelled']);
        $this->audit->record('pos.sale_cancelled', $actor, $sale->store->tenant, $sale);

        return $sale->fresh(['items.variant', 'payments', 'store', 'register']);
    }

    public function refreshPaymentTotals(Sale $sale): Sale
    {
        $verified = Payment::where('sale_id', $sale->id)->where('status', 'verified')->pluck('amount')->reduce(fn (string $carry, mixed $amount): string => $this->add($carry, (string) $amount), '0.00');
        $pending = Payment::where('sale_id', $sale->id)->where('status', 'pending')->exists();
        $status = bccomp($verified, (string) $sale->grand_total, 2) >= 0 ? 'paid' : (bccomp($verified, '0', 2) > 0 ? 'partially_paid' : ($pending ? 'pending' : 'unpaid'));
        $sale->update(['paid_total' => $verified, 'balance_due' => $this->subtractPositive((string) $sale->grand_total, $verified), 'change_due' => $this->subtractPositive($verified, (string) $sale->grand_total), 'payment_status' => $status, 'status' => $sale->status === 'completed' ? $sale->status : ($status === 'paid' ? 'pending_payment' : $sale->status)]);

        return $sale->refresh();
    }

    private function calculate(Store $store, array $payload, User $actor): array
    {
        // Refresh settings for every calculation so a request cannot reuse a
        // stale tenant/store relation after an administrator changes tax or
        // POS configuration.
        $store->load(['setting', 'tenant.businessSetting']);
        $posConfiguration = (array) ($store->tenant?->businessSetting?->pos_configuration ?? []);
        $rawQuantities = [];
        foreach ($payload['items'] as $input) {
            $key = (string) $input['variant_id'];
            $rawQuantities[$key] = bcadd((string) ($rawQuantities[$key] ?? '0'), $this->quantity((string) $input['quantity']), 3);
        }

        $variants = ProductVariant::with('product')->where('tenant_id', $store->tenant_id)->whereIn('uuid', array_keys($rawQuantities))->get()->keyBy('uuid');
        if ($variants->count() !== count($rawQuantities)) {
            throw new UnprocessableEntityHttpException('One or more product variants are not available in this business.');
        }
        $location = InventoryLocation::where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('location_type', 'store')->where('status', 'active')->first();
        if (! $location) {
            throw new UnprocessableEntityHttpException('This store has no active inventory location.');
        }

        $lines = [];
        $subtotal = '0.00';
        $itemDiscountTotal = '0.00';
        foreach ($payload['items'] as $input) {
            $variant = $variants->get((string) $input['variant_id']);
            $quantity = $this->quantity((string) $input['quantity']);
            if ($variant->status !== 'active' || $variant->product->status !== 'active') {
                throw new UnprocessableEntityHttpException('A selected product variant is inactive.');
            }
            if ($variant->product->track_inventory) {
                $balance = InventoryBalance::where('tenant_id', $store->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->first();
                $available = $balance ? bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3) : '0.000';
                if (bccomp($available, $rawQuantities[(string) $input['variant_id']], 3) < 0) {
                    throw new UnprocessableEntityHttpException('Insufficient available stock for '.$variant->sku.'.');
                }
            }
            $base = $this->money($this->multiply($quantity, (string) $variant->selling_price));
            $itemDiscount = $this->itemDiscount($input, $base);
            $itemDiscountTotal = $this->money($this->add($itemDiscountTotal, $itemDiscount));
            $subtotal = $this->money($this->add($subtotal, $base));
            $lines[] = ['variant' => $variant, 'quantity' => $quantity, 'base' => $base, 'item_discount' => $itemDiscount, 'discounted_base' => $this->money($this->subtract($base, $itemDiscount)), 'cost' => (string) ($variant->balances()->where('inventory_location_id', $location->id)->value('average_cost') ?: $variant->purchase_cost)];
        }

        $discountableSubtotal = array_reduce($lines, fn (string $carry, array $line): string => $this->money($this->add($carry, $line['discounted_base'])), '0.00');
        $cartDiscount = $this->discountValue($payload['discount_type'] ?? 'fixed', (string) ($payload['discount_value'] ?? $payload['discount_amount'] ?? '0'), $discountableSubtotal);
        $this->assertDiscountPolicy($store, $actor, $itemDiscountTotal, $cartDiscount, $subtotal, $posConfiguration);
        if (bccomp($cartDiscount, $discountableSubtotal, 2) > 0) {
            throw new UnprocessableEntityHttpException('The sale discount cannot exceed the subtotal after item discounts.');
        }

        $taxRate = $this->taxRate($store);
        $taxMode = $this->taxMode($store);
        $taxTotal = '0.00';
        $taxableProducts = '0.00';
        $productTotal = '0.00';
        $lineItems = [];
        $allocatedCartDiscount = '0.00';
        foreach ($lines as $index => $line) {
            $cartLineDiscount = $index === count($lines) - 1 ? $this->money($this->subtract($cartDiscount, $allocatedCartDiscount)) : $this->money($this->divide($this->multiply($cartDiscount, $line['discounted_base']), $discountableSubtotal ?: '1'));
            $allocatedCartDiscount = $this->money($this->add($allocatedCartDiscount, $cartLineDiscount));
            $lineDiscount = $this->money($this->add($line['item_discount'], $cartLineDiscount));
            $lineBaseAfterDiscount = $this->money($this->subtract($line['base'], $lineDiscount));
            $tax = $line['variant']->product->tax_exempt ? '0.00' : $this->taxFor($lineBaseAfterDiscount, $taxRate, $taxMode);
            $lineTotal = $taxMode === 'inclusive' ? $lineBaseAfterDiscount : $this->money($this->add($lineBaseAfterDiscount, $tax));
            if (! $line['variant']->product->tax_exempt) {
                $taxableProducts = $this->money($this->add($taxableProducts, $lineBaseAfterDiscount));
            }
            $taxTotal = $this->money($this->add($taxTotal, $tax));
            $productTotal = $this->money($this->add($productTotal, $lineTotal));
            $variant = $line['variant'];
            $lineItems[] = ['tenant_id' => $store->tenant_id, 'product_variant_id' => $variant->id, 'product_name_snapshot' => $variant->product->name, 'sku_snapshot' => $variant->sku, 'barcode_snapshot' => $variant->barcode, 'unit_snapshot' => $variant->unit, 'quantity' => $line['quantity'], 'unit_price' => $this->money((string) $variant->selling_price), 'discount_amount' => $lineDiscount, 'tax_amount' => $tax, 'line_total' => $lineTotal, 'unit_cost_at_sale' => $this->money((string) $line['cost'], 4), 'tax_snapshot' => ['rate' => $taxRate, 'mode' => $taxMode, 'exempt' => (bool) $variant->product->tax_exempt]];
        }

        // Apply configured service charges and fees after both item-level and
        // cart-level discounts. This keeps the server quote aligned with the
        // amount the customer is actually paying.
        $chargeBase = $this->money($this->subtract($discountableSubtotal, $cartDiscount));
        $service = $this->configuredCharge($posConfiguration['service_charge'] ?? [], $chargeBase);
        $fees = [];
        foreach ((array) ($posConfiguration['fees'] ?? []) as $configuredFee) {
            if (! is_array($configuredFee) || ($configuredFee['enabled'] ?? true) === false) {
                continue;
            }
            $fees[] = ['code' => $configuredFee['code'] ?? null, 'label' => (string) ($configuredFee['label'] ?? 'Fee'), 'amount' => $this->configuredCharge($configuredFee, $chargeBase)['amount'], 'taxable' => (bool) ($configuredFee['taxable'] ?? false)];
        }
        $feeTotal = array_reduce($fees, fn (string $carry, array $fee): string => $this->money($this->add($carry, $fee['amount'])), '0.00');
        $taxableCharges = ($service['taxable'] ?? false) ? $service['amount'] : '0.00';
        foreach ($fees as $fee) {
            if ($fee['taxable']) {
                $taxableCharges = $this->money($this->add($taxableCharges, $fee['amount']));
            }
        }
        $chargeTax = $this->taxFor($taxableCharges, $taxRate, $taxMode);
        $taxTotal = $this->money($this->add($taxTotal, $chargeTax));
        $taxableAmount = $this->money($this->add($taxableProducts, $taxableCharges));
        $rawGrand = $this->money($this->add($this->add($productTotal, $service['amount']), $feeTotal));
        if ($taxMode === 'exclusive') {
            $rawGrand = $this->money($this->add($rawGrand, $chargeTax));
        }
        [$grand, $roundingAdjustment] = $this->roundTotal($rawGrand, (array) ($posConfiguration['rounding'] ?? []));

        return ['currency_code' => $this->currency($store), 'subtotal' => $subtotal, 'discount_total' => $this->money($this->add($itemDiscountTotal, $cartDiscount)), 'item_discount_total' => $itemDiscountTotal, 'cart_discount_total' => $cartDiscount, 'taxable_amount' => $taxableAmount, 'tax_total' => $taxTotal, 'tax_breakdown' => [['label' => 'Products and configured charges', 'rate' => $taxRate, 'taxable_amount' => $taxableAmount, 'tax_amount' => $taxTotal]], 'service_charge_total' => $service['amount'], 'fee_total' => $feeTotal, 'fees' => $fees, 'rounding_adjustment' => $roundingAdjustment, 'grand_total' => $grand, 'items' => $lineItems];
    }

    private function assertStoreAccess(Store $store, User $actor): void
    {
        if (! $this->access->canOperate($actor, $store)) {
            throw new AccessDeniedHttpException('You cannot operate POS sales in this store.');
        }
    }

    private function itemDiscount(array $input, string $base): string
    {
        $type = $input['discount_type'] ?? 'fixed';
        $value = (string) ($input['discount_value'] ?? $input['discount_amount'] ?? '0');
        $discount = $type === 'percentage' ? $this->divide($this->multiply($base, $value), '100') : $value;
        $discount = $this->money($discount);
        if (bccomp($discount, '0.00', 2) < 0 || bccomp($discount, $base, 2) > 0) {
            throw new UnprocessableEntityHttpException('The item discount must be between zero and the line amount.');
        }

        return $discount;
    }

    private function discountValue(string $type, string $value, string $base): string
    {
        $discount = $type === 'percentage' ? $this->divide($this->multiply($base, $value), '100') : $value;
        $discount = $this->money($discount);
        if (bccomp($discount, '0.00', 2) < 0) {
            throw new UnprocessableEntityHttpException('The sale discount cannot be negative.');
        }

        return $discount;
    }

    private function assertDiscountPolicy(Store $store, User $actor, string $itemDiscount, string $cartDiscount, string $subtotal, array $configuration): void
    {
        $totalDiscount = $this->money($this->add($itemDiscount, $cartDiscount));
        if (bccomp($totalDiscount, '0.00', 2) <= 0) {
            return;
        }
        $manager = $this->access->canManage($actor, $store);
        if (! $manager) {
            $message = ($configuration['discount_requires_approval'] ?? false) === true
                ? 'Manager approval is required before applying this discount.'
                : 'Only authorized store managers can apply sale discounts.';
            throw new AccessDeniedHttpException($message);
        }
        $maxPercent = (string) ($configuration['discount_max_percent'] ?? 100);
        $maxAmount = (string) ($configuration['discount_max_amount'] ?? '0');
        if (bccomp($maxPercent, '0', 2) >= 0 && bccomp($this->multiply($totalDiscount, '100'), $this->multiply($subtotal, $maxPercent), 2) > 0) {
            throw new UnprocessableEntityHttpException('The discount exceeds the configured percentage limit.');
        }
        if (bccomp($maxAmount, '0', 2) > 0 && bccomp($totalDiscount, $maxAmount, 2) > 0) {
            throw new UnprocessableEntityHttpException('The discount exceeds the configured amount limit.');
        }
    }

    private function configuredCharge(array $configuration, string $base): array
    {
        if (($configuration['enabled'] ?? true) === false) {
            return ['amount' => '0.00', 'taxable' => (bool) ($configuration['taxable'] ?? false)];
        }
        $value = (string) ($configuration['value'] ?? $configuration['amount'] ?? '0');
        $amount = ($configuration['type'] ?? 'fixed') === 'percentage' ? $this->divide($this->multiply($base, $value), '100') : $value;
        if (bccomp($amount, '0', 2) < 0) {
            throw new UnprocessableEntityHttpException('Configured fees and service charges cannot be negative.');
        }

        return ['amount' => $this->money($amount), 'taxable' => (bool) ($configuration['taxable'] ?? false)];
    }

    private function taxFor(string $amount, string $rate, string $mode): string
    {
        if (bccomp($amount, '0.00', 2) <= 0 || bccomp($rate, '0.0000', 4) <= 0) {
            return '0.00';
        }

        return $this->money($mode === 'inclusive' ? $this->divide($this->multiply($amount, $rate), $this->add('100', $rate)) : $this->divide($this->multiply($amount, $rate), '100'));
    }

    private function roundTotal(string $amount, array $configuration): array
    {
        $increment = (string) ($configuration['increment'] ?? '0.01');
        if (bccomp($increment, '0', 2) <= 0 || bccomp($increment, '0.01', 2) === 0) {
            return [$this->money($amount), '0.00'];
        }
        $roundingMode = match ($configuration['mode'] ?? 'nearest') {
            'up' => RoundingMode::Ceiling,
            'down' => RoundingMode::Floor,
            default => RoundingMode::HalfUp,
        };
        $rounded = BigDecimal::of($amount)->dividedBy($increment, 0, $roundingMode)->multipliedBy($increment)->toScale(2, RoundingMode::HalfUp)->__toString();

        return [$rounded, $this->money($this->subtract($rounded, $amount))];
    }

    private function assertRegisterAccess(Store $store, PosRegister $register, User $actor): void
    {
        if ((int) $register->tenant_id !== (int) $store->tenant_id || (int) $register->store_id !== (int) $store->id || $register->status !== 'active' || ! $this->access->canOperateRegister($actor, $register)) {
            throw new AccessDeniedHttpException('You cannot operate this register.');
        }
    }

    private function assertOpenShift(Store $store, PosRegister $register, User $actor): void
    {
        if (! CashierShift::where('tenant_id', $store->tenant_id)->where('store_id', $store->id)->where('register_id', $register->id)->where('cashier_user_id', $actor->id)->where('status', 'open')->exists()) {
            throw new ConflictHttpException('Open an authorized cashier shift before creating a sale.');
        }
    }

    private function hashPayload(array $payload, Store $store, PosRegister $register): string
    {
        return hash('sha256', json_encode([
            'store_id' => $store->uuid,
            'register_id' => $register->uuid,
            'items' => $payload['items'],
            'discount_type' => $payload['discount_type'] ?? 'fixed',
            'discount_value' => $payload['discount_value'] ?? $payload['discount_amount'] ?? '0',
            'customer_id' => $payload['customer_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    private function nextNumber(Store $store, string $prefix): string
    {
        do {
            $number = $prefix.'-'.now($store->timezone ?: 'UTC')->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Sale::where('tenant_id', $store->tenant_id)->where('sale_number', $number)->exists());

        return $number;
    }

    private function taxRate(Store $store): string
    {
        $storeConfig = $store->setting?->tax_configuration ?? [];
        $businessConfig = $store->tenant?->businessSetting?->tax_configuration ?? [];
        $rate = $storeConfig['rate'] ?? $storeConfig['default_rate'] ?? $businessConfig['rate'] ?? $businessConfig['default_rate'] ?? config('pos.default_tax_rate', '0');

        $decimalRate = BigDecimal::of((string) $rate);

        return $decimalRate->isNegative() ? '0.0000' : $decimalRate->toScale(4, RoundingMode::HalfUp)->__toString();
    }

    private function taxMode(Store $store): string
    {
        $configuration = $store->setting?->tax_configuration ?? [];
        $businessPayment = $store->tenant?->businessSetting?->payment_configuration ?? [];
        $businessTax = $store->tenant?->businessSetting?->tax_configuration ?? [];

        return (($configuration['mode'] ?? $businessPayment['tax_mode'] ?? $businessTax['mode'] ?? 'exclusive') === 'inclusive') ? 'inclusive' : 'exclusive';
    }

    private function holdResumeEnabled(Store $store): bool
    {
        $store->loadMissing('tenant.businessSetting');
        $configuration = $store->tenant?->businessSetting?->pos_configuration ?? [];

        return ($configuration['hold_resume_enabled'] ?? true) === true;
    }

    private function currency(Store $store): string
    {
        return strtoupper((string) ($store->setting?->tenant?->businessSetting?->currency_code ?? $store->tenant?->businessSetting?->currency_code ?? config('pos.default_currency', 'MMK')));
    }

    private function quantity(string $value): string
    {
        return BigDecimal::of($value)->toScale(3, RoundingMode::HalfUp)->__toString();
    }

    private function money(string $value, int $scale = 2): string
    {
        return BigDecimal::of($value)->toScale($scale, RoundingMode::HalfUp)->__toString();
    }

    private function add(string $a, string $b): string
    {
        return BigDecimal::of($a)->plus($b)->__toString();
    }

    private function subtract(string $a, string $b): string
    {
        return BigDecimal::of($a)->minus($b)->__toString();
    }

    private function subtractPositive(string $a, string $b): string
    {
        $difference = BigDecimal::of($a)->minus($b);

        return $difference->isNegative() ? '0.00' : $difference->toScale(2, RoundingMode::HalfUp)->__toString();
    }

    private function multiply(string $a, string $b): string
    {
        return BigDecimal::of($a)->multipliedBy($b)->__toString();
    }

    private function divide(string $a, string $b): string
    {
        return BigDecimal::of($a)->dividedBy($b, 8, RoundingMode::HalfUp)->__toString();
    }
}
