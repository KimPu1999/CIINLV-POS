<?php

namespace App\Services;

use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderCommission;
use App\Models\MarketplaceSettlementEntry;
use App\Models\MarketplaceVendorOrder;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketplaceCommissionService
{
    public function __construct(private readonly MarketplaceSettingsService $settings)
    {
    }

    public function storageAvailable(): bool
    {
        return Schema::hasTable('marketplace_order_commissions') && Schema::hasTable('marketplace_settlement_entries');
    }

    /** @return array{rule_id:int|null, rate:string, base:string, source:string} */
    public function effectiveRule(int $tenantId, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        if ($this->settings->storageAvailable()) {
            $rule = MarketplaceCommissionRule::query()
                ->where('status', 'active')
                ->where('effective_from', '<=', $at)
                ->where(function (Builder $query) use ($at): void {
                    $query->whereNull('effective_until')->orWhere('effective_until', '>', $at);
                })
                ->where(function (Builder $query) use ($tenantId): void {
                    $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
                })
                ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
                ->orderByDesc('effective_from')
                ->first();
            if ($rule) {
                return ['rule_id' => $rule->id, 'rate' => (string) $rule->rate, 'base' => $rule->commission_base, 'source' => $rule->tenant_id === null ? 'global' : 'seller'];
            }
        }

        $current = $this->settings->current()['settings'];

        return ['rule_id' => null, 'rate' => (string) $current['default_commission_rate'], 'base' => (string) $current['commission_base'], 'source' => 'default'];
    }

    /** @return array{base_amount:string, rate:string, commission_amount:string, seller_net_amount:string, currency_code:string, rule:array} */
    public function preview(int $tenantId, string $baseAmount, ?string $currency = null, ?CarbonImmutable $at = null): array
    {
        $rule = $this->effectiveRule($tenantId, $at);
        $base = $this->money($baseAmount);
        $commission = $this->money(bcdiv(bcmul($base, $rule['rate'], 8), '100', 8));

        return [
            'base_amount' => $base,
            'rate' => $this->rate($rule['rate']),
            'commission_amount' => $commission,
            'seller_net_amount' => $this->money(bcsub($base, $commission, 8)),
            'currency_code' => strtoupper($currency ?: $this->settings->current()['settings']['currency_code']),
            'rule' => $rule,
        ];
    }

    public function snapshotForVendorOrder(MarketplaceOrder $order, MarketplaceVendorOrder $vendorOrder): ?MarketplaceOrderCommission
    {
        if (! $this->storageAvailable()) {
            return null;
        }

        return DB::transaction(function () use ($order, $vendorOrder): MarketplaceOrderCommission {
            $existing = MarketplaceOrderCommission::query()->where('marketplace_vendor_order_id', $vendorOrder->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $preview = $this->preview((int) $vendorOrder->tenant_id, (string) $vendorOrder->subtotal, (string) $vendorOrder->currency_code);
            $commission = MarketplaceOrderCommission::create([
                'marketplace_order_id' => $order->id,
                'marketplace_vendor_order_id' => $vendorOrder->id,
                'tenant_id' => $vendorOrder->tenant_id,
                'marketplace_commission_rule_id' => $preview['rule']['rule_id'],
                'currency_code' => $preview['currency_code'],
                'commission_base_amount' => $preview['base_amount'],
                'commission_rate' => $preview['rate'],
                'commission_amount' => $preview['commission_amount'],
                'seller_net_amount' => $this->money(bcsub((string) $vendorOrder->total, $preview['commission_amount'], 8)),
                'status' => 'pending',
                'calculated_at' => now(),
                'metadata' => ['base_definition' => 'vendor_order.subtotal_excluding_tax_shipping_and_payment_fees'],
            ]);

            MarketplaceSettlementEntry::firstOrCreate(
                ['idempotency_key' => 'commission:'.$vendorOrder->uuid],
                ['marketplace_order_id' => $order->id, 'marketplace_vendor_order_id' => $vendorOrder->id, 'marketplace_order_commission_id' => $commission->id, 'tenant_id' => $vendorOrder->tenant_id, 'entry_type' => 'platform_commission', 'direction' => 'credit', 'amount' => $commission->commission_amount, 'currency_code' => $commission->currency_code, 'status' => 'pending', 'metadata' => ['source' => 'order_commission_snapshot']],
            );
            MarketplaceSettlementEntry::firstOrCreate(
                ['idempotency_key' => 'seller-earning:'.$vendorOrder->uuid],
                ['marketplace_order_id' => $order->id, 'marketplace_vendor_order_id' => $vendorOrder->id, 'marketplace_order_commission_id' => $commission->id, 'tenant_id' => $vendorOrder->tenant_id, 'entry_type' => 'seller_earning', 'direction' => 'credit', 'amount' => $commission->seller_net_amount, 'currency_code' => $commission->currency_code, 'status' => 'pending', 'metadata' => ['source' => 'order_commission_snapshot']],
            );

            return $commission;
        });
    }

    public function markOrderPayable(MarketplaceOrder $order): void
    {
        if (! $this->storageAvailable()) {
            return;
        }

        $order->commissions()->where('status', 'pending')->update(['status' => 'payable']);
        MarketplaceSettlementEntry::query()->where('marketplace_order_id', $order->id)->where('status', 'pending')->update(['status' => 'available']);
    }

    public function reversePending(MarketplaceOrder $order): void
    {
        if (! $this->storageAvailable()) {
            return;
        }

        $order->commissions()->where('status', 'pending')->update(['status' => 'reversed']);
        MarketplaceSettlementEntry::query()->where('marketplace_order_id', $order->id)->where('status', 'pending')->update(['status' => 'reversed']);
    }

    public function report(array $filters): array
    {
        if (! $this->storageAvailable()) {
            return ['configured' => false, 'summary' => ['commission_total' => '0.00', 'seller_earnings_total' => '0.00', 'rows' => 0], 'data' => []];
        }

        $query = MarketplaceOrderCommission::query()->with(['order:id,uuid,order_number,created_at', 'tenant:id,name'])->when($filters['tenant_id'] ?? null, fn (Builder $builder, $tenantId) => $builder->where('tenant_id', $tenantId))->when($filters['status'] ?? null, fn (Builder $builder, $status) => $builder->where('status', $status))->when($filters['from'] ?? null, fn (Builder $builder, $from) => $builder->whereDate('created_at', '>=', $from))->when($filters['to'] ?? null, fn (Builder $builder, $to) => $builder->whereDate('created_at', '<=', $to));
        $summary = (clone $query)->selectRaw("COALESCE(SUM(commission_amount), 0) AS commission_total, COALESCE(SUM(seller_net_amount), 0) AS seller_earnings_total, COUNT(*) AS rows")->first();
        $rows = $query->latest()->paginate($filters['per_page'] ?? 25);

        return ['configured' => true, 'summary' => ['commission_total' => $this->money($summary?->commission_total), 'seller_earnings_total' => $this->money($summary?->seller_earnings_total), 'rows' => (int) ($summary?->rows ?? 0)], 'data' => $rows];
    }

    public function payoutSummaries(array $filters): array
    {
        if (! $this->storageAvailable()) {
            return ['configured' => false, 'provider' => 'not_configured', 'data' => []];
        }

        $rows = MarketplaceSettlementEntry::query()->select('tenant_id', 'currency_code')->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'seller_earning' AND status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount")->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'seller_earning' AND status = 'available' THEN amount ELSE 0 END), 0) AS available_amount")->when($filters['tenant_id'] ?? null, fn (Builder $query, $tenantId) => $query->where('tenant_id', $tenantId))->groupBy('tenant_id', 'currency_code')->with('tenant:id,name')->paginate($filters['per_page'] ?? 25);

        return ['configured' => true, 'provider' => 'not_configured', 'data' => $rows];
    }

    /** @return array{commission_revenue:string, vendor_liabilities:string} */
    public function dashboardTotals(array $range): array
    {
        if (! $this->storageAvailable()) {
            return ['commission_revenue' => '0.00', 'vendor_liabilities' => '0.00'];
        }

        $commission = MarketplaceOrderCommission::query()->whereBetween('created_at', $range);

        return [
            'commission_revenue' => $this->money((clone $commission)->whereIn('status', ['payable'])->sum('commission_amount')),
            'vendor_liabilities' => $this->money((clone $commission)->whereIn('status', ['pending', 'payable'])->sum('seller_net_amount')),
        ];
    }

    private function rate(string $value): string
    {
        return BigDecimal::of($value)->toScale(4, RoundingMode::HalfUp)->__toString();
    }

    private function money(mixed $value): string
    {
        return BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HalfUp)->__toString();
    }
}
