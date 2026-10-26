<?php

namespace App\Services;

use App\Models\MarketplaceCommissionRule;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderCommission;
use App\Models\MarketplaceSettlementEntry;
use App\Models\MarketplaceVendorOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketplaceCommissionService
{
    public const REPORT_PER_PAGE = [10, 20, 50, 100];

    public function __construct(private readonly MarketplaceSettingsService $settings) {}

    public function storageAvailable(): bool
    {
        return Schema::hasTable('marketplace_order_commissions') && Schema::hasTable('marketplace_settlement_entries');
    }

    public function advancedRuleStorageAvailable(): bool
    {
        return $this->storageAvailable()
            && Schema::hasColumn('marketplace_commission_rules', 'rule_type')
            && Schema::hasColumn('marketplace_commission_rules', 'category_id');
    }

    /** @return array{rule_id:int|null, rate:string, base:string, source:string, rule_type:string, configured:bool} */
    public function effectiveRule(int $tenantId, ?CarbonImmutable $at = null, ?int $categoryId = null, ?string $promotionCode = null): array
    {
        $at ??= CarbonImmutable::now();
        if ($this->settings->storageAvailable()) {
            $query = MarketplaceCommissionRule::query()
                ->where('status', 'active')
                ->where('effective_from', '<=', $at)
                ->where(function (Builder $builder) use ($at): void {
                    $builder->whereNull('effective_until')->orWhere('effective_until', '>', $at);
                });

            if ($this->advancedRuleStorageAvailable()) {
                $query->where(function (Builder $builder) use ($tenantId, $categoryId, $promotionCode): void {
                    $builder
                        ->where(fn (Builder $scope) => $scope->where('rule_type', 'business')->where('tenant_id', $tenantId)->whereNull('category_id'))
                        ->orWhere(fn (Builder $scope) => $scope->where('rule_type', 'category')->whereNull('tenant_id')->where('category_id', $categoryId))
                        ->orWhere(fn (Builder $scope) => $scope->where('rule_type', 'category')->where('tenant_id', $tenantId)->where('category_id', $categoryId))
                        ->orWhere(function (Builder $scope) use ($tenantId, $categoryId, $promotionCode): void {
                            $scope->where('rule_type', 'promotional')
                                ->where(function (Builder $tenant) use ($tenantId): void {
                                    $tenant->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                                })
                                ->where(function (Builder $category) use ($categoryId): void {
                                    $category->whereNull('category_id')->orWhere('category_id', $categoryId);
                                })
                                ->when($promotionCode, function (Builder $promotion) use ($promotionCode): void {
                                    $promotion->where(function (Builder $code) use ($promotionCode): void {
                                        $code->where('promotion_code', $promotionCode)->orWhereNull('promotion_code');
                                    });
                                })
                                ->when(! $promotionCode, fn (Builder $promotion) => $promotion->whereNull('promotion_code'));
                        })
                        ->orWhere(fn (Builder $scope) => $scope->where('rule_type', 'global')->whereNull('tenant_id')->whereNull('category_id'));
                });

                $rule = $query
                    ->orderByRaw("CASE WHEN rule_type = 'promotional' THEN 0 WHEN rule_type = 'business' THEN 1 WHEN rule_type = 'category' THEN 2 ELSE 3 END")
                    ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
                    ->when($promotionCode, fn (Builder $builder) => $builder->orderByRaw('CASE WHEN promotion_code = ? THEN 0 ELSE 1 END', [$promotionCode]))
                    ->orderByDesc('priority')
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->first();
            } else {
                $rule = $query
                    ->where(fn (Builder $builder) => $builder->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
                    ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$tenantId])
                    ->orderByDesc('effective_from')
                    ->first();
            }

            if ($rule) {
                $type = $this->advancedRuleStorageAvailable()
                    ? (string) ($rule->rule_type ?: ($rule->tenant_id === null ? 'global' : 'business'))
                    : ($rule->tenant_id === null ? 'global' : 'business');

                return [
                    'rule_id' => $rule->id,
                    'rate' => (string) $rule->rate,
                    'base' => $rule->commission_base,
                    'source' => match ($type) {
                        'promotional' => 'promotional',
                        'business' => 'business',
                        'category' => 'category',
                        default => 'global',
                    },
                    'rule_type' => $type,
                    'configured' => true,
                ];
            }
        }

        $currentState = $this->settings->current();
        $current = $currentState['settings'];

        return ['rule_id' => null, 'rate' => (string) $current['default_commission_rate'], 'base' => (string) $current['commission_base'], 'source' => 'default', 'rule_type' => 'global', 'configured' => (bool) $currentState['configured']];
    }

    /** @return array{base_amount:string, rate:string, commission_amount:string, seller_net_amount:string, currency_code:string, rule:array} */
    public function preview(int $tenantId, string $baseAmount, ?string $currency = null, ?CarbonImmutable $at = null, ?int $categoryId = null, ?string $promotionCode = null): array
    {
        $rule = $this->effectiveRule($tenantId, $at, $categoryId, $promotionCode);
        $base = $this->money($baseAmount);
        $commission = $this->money(bcdiv(bcmul($base, $rule['rate'], 8), '100', 8));

        return [
            'base_amount' => $base,
            'rate' => $this->rate($rule['rate']),
            'commission_amount' => $commission,
            'seller_net_amount' => $this->money(bcsub($base, $commission, 8)),
            'currency_code' => strtoupper($currency ?: $this->settings->current()['settings']['currency_code']),
            'configured' => $rule['configured'],
            'commission_base_policy' => [
                'key' => $rule['base'],
                'includes' => ['item_subtotal_after_discounts'],
                'excludes' => ['tax', 'shipping', 'payment_fees'],
            ],
            'rule' => $rule,
        ];
    }

    public function snapshotForVendorOrder(MarketplaceOrder $order, MarketplaceVendorOrder $vendorOrder, ?string $promotionCode = null): ?MarketplaceOrderCommission
    {
        if (! $this->storageAvailable()) {
            return null;
        }

        return DB::transaction(function () use ($order, $vendorOrder, $promotionCode): MarketplaceOrderCommission {
            $existing = MarketplaceOrderCommission::query()->where('marketplace_vendor_order_id', $vendorOrder->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $vendorOrder->loadMissing('items.product');
            $linePreviews = [];
            foreach ($vendorOrder->items as $item) {
                $lineBase = bcsub(bcmul((string) $item->unit_price, (string) $item->quantity, 8), (string) ($item->discount_amount ?? '0'), 8);
                if (bccomp($lineBase, '0', 8) < 0) {
                    throw new \UnexpectedValueException('Order item discounts cannot exceed the item subtotal.');
                }
                $linePreviews[] = [
                    'item' => $item,
                    'preview' => $this->preview((int) $vendorOrder->tenant_id, $lineBase, (string) $vendorOrder->currency_code, CarbonImmutable::now(), $item->product?->category_id, $promotionCode),
                ];
            }

            if ($linePreviews === []) {
                $commissionBase = bcsub((string) $vendorOrder->subtotal, (string) ($vendorOrder->discount_total ?? '0'), 8);
                if (bccomp($commissionBase, '0', 8) < 0) {
                    throw new \UnexpectedValueException('Vendor order discounts cannot exceed the item subtotal.');
                }
                $linePreviews[] = ['item' => null, 'preview' => $this->preview((int) $vendorOrder->tenant_id, $commissionBase, (string) $vendorOrder->currency_code, null, null, $promotionCode)];
            }

            $baseAmount = '0';
            $commissionAmount = '0';
            $ruleIds = [];
            $lineRules = [];
            foreach ($linePreviews as $line) {
                $preview = $line['preview'];
                $baseAmount = bcadd($baseAmount, $preview['base_amount'], 8);
                $commissionAmount = bcadd($commissionAmount, $preview['commission_amount'], 8);
                $ruleIds[] = $preview['rule']['rule_id'];
                $lineRules[] = [
                    'item_id' => $line['item']?->uuid,
                    'category_id' => $line['item']?->product?->category_id,
                    'rule_id' => $preview['rule']['rule_id'],
                    'rule_type' => $preview['rule']['rule_type'] ?? $preview['rule']['source'],
                    'rate' => $preview['rate'],
                    'base_amount' => $preview['base_amount'],
                    'commission_amount' => $preview['commission_amount'],
                ];
            }

            $baseAmount = $this->money($baseAmount);
            $commissionAmount = $this->money($commissionAmount);
            $weightedRate = bccomp($baseAmount, '0', 8) === 0 ? '0' : bcdiv(bcmul($commissionAmount, '100', 8), $baseAmount, 4);
            $ruleId = count(array_unique($ruleIds)) === 1 ? $ruleIds[0] : null;
            $sellerNet = $this->money(bcsub((string) $vendorOrder->total, $commissionAmount, 8));
            if (bccomp($sellerNet, '0', 8) < 0) {
                throw new \UnexpectedValueException('The commission exceeds the vendor order total.');
            }

            $commission = MarketplaceOrderCommission::create([
                'marketplace_order_id' => $order->id,
                'marketplace_vendor_order_id' => $vendorOrder->id,
                'tenant_id' => $vendorOrder->tenant_id,
                'marketplace_commission_rule_id' => $ruleId,
                'currency_code' => strtoupper((string) $vendorOrder->currency_code),
                'commission_base_amount' => $baseAmount,
                'commission_rate' => $this->rate($weightedRate),
                'commission_amount' => $commissionAmount,
                'seller_net_amount' => $sellerNet,
                'status' => 'pending',
                'calculated_at' => now(),
                'metadata' => [
                    'base_definition' => 'item_subtotal_minus_item_discounts_excluding_tax_shipping_and_payment_fees',
                    'line_rules' => $lineRules,
                ],
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
            return ['configured' => false, 'summary' => ['eligible_sales_total' => '0.00', 'commission_total' => '0.00', 'seller_earnings_total' => '0.00', 'rows' => 0], 'data' => []];
        }

        $query = $this->reportQuery($filters);
        $summaryQuery = (clone $query)->reorder();
        if (! ($filters['status'] ?? null)) {
            $summaryQuery->whereIn('status', ['pending', 'payable']);
        }
        $summary = $summaryQuery->selectRaw('COALESCE(SUM(commission_base_amount), 0) AS eligible_sales_total, COALESCE(SUM(commission_amount), 0) AS commission_total, COALESCE(SUM(seller_net_amount), 0) AS seller_earnings_total, COUNT(*) AS rows')->first();
        $rows = $query->paginate($this->reportPerPage((int) ($filters['per_page'] ?? 20)));
        $rows->setCollection($rows->getCollection()->map(fn (MarketplaceOrderCommission $commission): array => $this->reportRow($commission)));

        return ['configured' => true, 'summary' => ['eligible_sales_total' => $this->money($summary?->eligible_sales_total), 'commission_total' => $this->money($summary?->commission_total), 'seller_earnings_total' => $this->money($summary?->seller_earnings_total), 'rows' => (int) ($summary?->rows ?? 0)], 'data' => $rows];
    }

    public function reportQuery(array $filters): Builder
    {
        return MarketplaceOrderCommission::query()
            ->with([
                'order:id,uuid,order_number,grand_total,created_at',
                'vendorOrder:id,uuid,vendor_order_number,total',
                'tenant:id,name,business_code',
                'settlementEntries.payoutItem.payout:id,uuid,status,provider_reference',
            ])
            ->when($filters['tenant_id'] ?? null, fn (Builder $builder, $tenantId) => $builder->where('tenant_id', $tenantId))
            ->when($filters['status'] ?? null, fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $builder, $from) => $builder->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $builder, $to) => $builder->whereDate('created_at', '<=', $to))
            ->when($filters['search'] ?? null, function (Builder $builder, $search): void {
                $term = '%'.mb_strtolower(trim((string) $search)).'%';
                $builder->where(function (Builder $query) use ($term): void {
                    $query->whereHas('order', fn (Builder $order) => $order->whereRaw('LOWER(order_number) LIKE ?', [$term]))
                        ->orWhereHas('vendorOrder', fn (Builder $vendorOrder) => $vendorOrder->whereRaw('LOWER(vendor_order_number) LIKE ?', [$term]))
                        ->orWhereHas('tenant', fn (Builder $tenant) => $tenant->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereRaw('LOWER(business_code) LIKE ?', [$term]))
                        ->orWhereRaw('LOWER(currency_code) LIKE ?', [$term]);
                });
            })
            ->orderBy($this->reportSort((string) ($filters['sort'] ?? 'created_at')), ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderByDesc('id');
    }

    public function reportRow(MarketplaceOrderCommission $commission): array
    {
        $commission->loadMissing(['order', 'vendorOrder', 'tenant', 'settlementEntries.payoutItem.payout']);
        $sellerEntry = $commission->settlementEntries->firstWhere('entry_type', 'seller_earning');
        $payout = $sellerEntry?->payoutItem?->payout;

        return [
            'commission_id' => $commission->uuid,
            'order_number' => $commission->order?->order_number,
            'vendor_order_number' => $commission->vendorOrder?->vendor_order_number,
            'business_id' => $commission->tenant?->business_code ?: (string) $commission->tenant_id,
            'business_name' => $commission->tenant?->name,
            'gross_amount' => $this->money((string) ($commission->vendorOrder?->total ?? $commission->order?->grand_total ?? $commission->commission_base_amount)),
            'commission_base_amount' => $this->money($commission->commission_base_amount),
            'commission_rate' => $this->rate($commission->commission_rate),
            'commission_amount' => $this->money($commission->commission_amount),
            'seller_net_amount' => $this->money($commission->seller_net_amount),
            'payout_status' => $payout?->status ?: ($sellerEntry?->status ?: $commission->status),
            'status' => $commission->status,
            'currency_code' => $commission->currency_code,
            'calculated_at' => $commission->calculated_at?->toIso8601String(),
            'date' => $commission->created_at?->toIso8601String(),
        ];
    }

    private function reportPerPage(int $perPage): int
    {
        return in_array($perPage, self::REPORT_PER_PAGE, true) ? $perPage : 20;
    }

    private function reportSort(string $sort): string
    {
        return match ($sort) {
            'commission_amount', 'commission_rate', 'seller_net_amount', 'created_at' => $sort,
            default => 'created_at',
        };
    }

    public function businessSummaries(?array $tenantIds = null): array
    {
        if (! $this->storageAvailable()) {
            return [];
        }

        $rows = MarketplaceOrderCommission::query()
            ->select('tenant_id')
            ->selectRaw('COALESCE(SUM(commission_base_amount), 0) AS eligible_sales_total')
            ->selectRaw('COALESCE(SUM(commission_amount), 0) AS platform_commission_total')
            ->selectRaw('COALESCE(SUM(seller_net_amount), 0) AS seller_earnings_total')
            ->whereIn('status', ['pending', 'payable'])
            ->when($tenantIds, fn (Builder $builder) => $builder->whereIn('tenant_id', $tenantIds))
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        return $rows->map(fn ($row): array => [
            'eligible_sales_total' => $this->money($row->eligible_sales_total),
            'platform_commission_total' => $this->money($row->platform_commission_total),
            'seller_earnings_total' => $this->money($row->seller_earnings_total),
        ])->all();
    }

    public function tenantEarnings(int $tenantId, ?string $currency = null, array $filters = []): array
    {
        if (! $this->storageAvailable()) {
            return ['configured' => false, 'pending' => '0.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '0.00', 'transactions' => []];
        }

        $currency = $currency ? strtoupper($currency) : null;
        $base = $this->earningsEntryQuery($tenantId, $currency, $filters);
        $entries = (clone $base)->with($this->earningsRelations())->orderBy('id')->get();
        $rows = $entries->flatMap(fn (MarketplaceSettlementEntry $entry): array => $this->entryRows($entry));
        $rows = $this->filterAllocatedRows($rows, $filters['branch_id'] ?? null);

        $balanceRows = (clone $this->earningsEntryQuery($tenantId, $currency, ['branch_id' => $filters['branch_id'] ?? null]))
            ->with($this->earningsRelations())
            ->orderBy('id')
            ->get()
            ->flatMap(fn (MarketplaceSettlementEntry $entry): array => $this->entryRows($entry));
        $balanceRows = $this->filterAllocatedRows($balanceRows, $filters['branch_id'] ?? null);
        $sellerRows = $balanceRows->where('entry_type', 'seller_earning');
        $pending = $sellerRows->where('status', 'pending')->sum('amount');
        $available = $sellerRows->where('status', 'available')->reject(fn (array $row): bool => $row['reserved'])->sum('amount');
        $reserved = $sellerRows->where('status', 'available')->filter(fn (array $row): bool => $row['reserved'])->sum('amount');
        $paid = $balanceRows->where('entry_type', 'payout')->where('direction', 'debit')->where('status', 'available')->sum('amount');
        $commission = $balanceRows->where('entry_type', 'platform_commission');
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = in_array((int) ($filters['per_page'] ?? 25), [10, 25, 50, 100], true) ? (int) ($filters['per_page'] ?? 25) : 25;
        $transactions = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);

        return [
            'configured' => true,
            'pending' => $this->money((string) $pending),
            'available' => $this->money((string) $available),
            'reserved' => $this->money((string) $reserved),
            'paid' => $this->money((string) $paid),
            'commission_amounts' => ['pending' => $this->money((string) $commission->where('status', 'pending')->sum('amount')), 'available' => $this->money((string) $commission->where('status', 'available')->sum('amount')), 'reversed' => $this->money((string) $commission->where('status', 'reversed')->sum('amount'))],
            'transactions' => $transactions,
        ];
    }

    private function earningsEntryQuery(int $tenantId, ?string $currency, array $filters): Builder
    {
        $query = MarketplaceSettlementEntry::query()
            ->where('tenant_id', $tenantId)
            ->when($currency, fn (Builder $builder) => $builder->where('currency_code', $currency))
            ->when($filters['entry_type'] ?? null, fn (Builder $builder, $value) => $builder->where('entry_type', $value))
            ->when($filters['direction'] ?? null, fn (Builder $builder, $value) => $builder->where('direction', $value))
            ->when($filters['status'] ?? null, fn (Builder $builder, $value) => $builder->where('status', $value))
            ->when($filters['from'] ?? null, fn (Builder $builder, $value) => $builder->where('created_at', '>=', CarbonImmutable::parse($value, $filters['timezone'] ?? config('app.timezone'))->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $builder, $value) => $builder->where('created_at', '<=', CarbonImmutable::parse($value, $filters['timezone'] ?? config('app.timezone'))->endOfDay()->utc()))
            ->when($filters['search'] ?? null, function (Builder $builder, $value): void {
                $term = '%'.mb_strtolower(trim((string) $value)).'%';
                $builder->where(function (Builder $search) use ($term): void {
                    $search->whereRaw('LOWER(idempotency_key) LIKE ?', [$term])
                        ->orWhereHas('order', fn (Builder $order) => $order->whereRaw('LOWER(order_number) LIKE ?', [$term]))
                        ->orWhereHas('vendorOrder', fn (Builder $order) => $order->whereRaw('LOWER(vendor_order_number) LIKE ?', [$term]));
                });
            });
        if (! empty($filters['branch_id'])) {
            $query->where(function (Builder $branch) use ($filters): void {
                if ($filters['branch_id'] === '__unassigned__') {
                    $branch->whereDoesntHave('vendorOrder')
                        ->orWhereHas('vendorOrder.items', fn (Builder $item) => $item->whereDoesntHave('reservations')->orWhereHas('reservations.balance.location', fn (Builder $location) => $location->whereNull('store_id')))
                        ->orWhereHas('payoutItem.payout.items.settlementEntry.vendorOrder.items', fn (Builder $item) => $item->whereDoesntHave('reservations')->orWhereHas('reservations.balance.location', fn (Builder $location) => $location->whereNull('store_id')));

                    return;
                }
                $branch->whereHas('vendorOrder.items.reservations.balance.location.store', fn (Builder $store) => $store->where('uuid', $filters['branch_id']))
                    ->orWhereHas('payoutItem.payout.items.settlementEntry.vendorOrder.items.reservations.balance.location.store', fn (Builder $store) => $store->where('uuid', $filters['branch_id']));
            });
        }
        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction_sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort === 'amount' ? 'amount' : $sort, $direction)->orderBy('id', 'desc');
    }

    private function earningsRelations(): array
    {
        return [
            'order:id,uuid,order_number',
            'commission:id,marketplace_vendor_order_id,metadata',
            'vendorOrder:id,uuid,vendor_order_number,total,currency_code',
            'vendorOrder.items:id,uuid,marketplace_vendor_order_id,quantity,unit_price,discount_amount',
            'vendorOrder.items.reservations:id,marketplace_order_item_id,inventory_balance_id,quantity',
            'vendorOrder.items.reservations.balance:id,inventory_location_id',
            'vendorOrder.items.reservations.balance.location:id,store_id',
            'vendorOrder.items.reservations.balance.location.store:id,uuid,name,code',
            'payoutItem.payout.items.settlementEntry.vendorOrder.items.reservations.balance.location.store:id,uuid,name,code',
            'payoutItem.payout.items.settlementEntry.commission:id,marketplace_vendor_order_id,metadata',
            'payoutItem.payout.items.settlementEntry.vendorOrder.items.reservations.balance.location:id,store_id',
            'payoutItem.payout.items.settlementEntry.vendorOrder.items.reservations.balance:id,inventory_location_id',
        ];
    }

    private function entryRows(MarketplaceSettlementEntry $entry): array
    {
        if ($entry->entry_type === 'payout') {
            $sourceRows = collect($entry->payoutItem?->payout?->items ?? [])
                ->flatMap(fn ($item) => $item->settlementEntry ? $this->entryAllocations($item->settlementEntry) : [])
                ->values();
            if ($sourceRows->isNotEmpty()) {
                $sourceTotal = (string) $sourceRows->sum('amount');

                return $sourceRows->map(fn (array $row): array => $this->ledgerRow($entry, $row['branch'], $this->proportionalAmount((string) $entry->amount, (string) $row['amount'], $sourceTotal), false, $sourceRows->all()))->all();
            }
        }
        $reserved = $entry->payoutItem?->payout && in_array($entry->payoutItem->payout->status, ['requested', 'approved', 'processing'], true);
        $allocations = collect($this->entryAllocations($entry));

        return $allocations->map(fn (array $allocation): array => $this->ledgerRow($entry, $allocation['branch'], $allocation['amount'], $reserved, $allocations->all()))->all();
    }

    private function ledgerRow(MarketplaceSettlementEntry $entry, ?array $branch, string $amount, bool $reserved, array $breakdown = []): array
    {
        return ['id' => $entry->uuid ?: (string) $entry->id, 'entry_id' => $entry->id, 'entry_type' => $entry->entry_type, 'direction' => $entry->direction, 'amount' => $amount, 'original_amount' => $this->money($entry->amount), 'currency_code' => $entry->currency_code, 'status' => $entry->status, 'created_at' => $entry->created_at?->toIso8601String(), 'reference' => $entry->vendorOrder?->vendor_order_number ?: $entry->order?->order_number ?: $entry->idempotency_key, 'branch' => $branch, 'branch_breakdown' => $breakdown, 'reserved' => $reserved];
    }

    private function entryAllocations(MarketplaceSettlementEntry $entry): array
    {
        $vendorOrder = $entry->vendorOrder;
        if (! $vendorOrder) {
            return [['branch' => null, 'amount' => $this->money($entry->amount)]];
        }
        $lineRules = collect(($entry->commission?->metadata ?? [])['line_rules'] ?? [])->keyBy('item_id');
        $parts = [];
        foreach ($vendorOrder->items as $item) {
            $rule = $lineRules->get($item->uuid);
            $base = (string) ($rule['base_amount'] ?? bcsub(bcmul((string) $item->unit_price, (string) $item->quantity, 8), (string) ($item->discount_amount ?? '0'), 8));
            $lineAmount = $entry->entry_type === 'platform_commission' ? (string) ($rule['commission_amount'] ?? '0') : bcsub($base, (string) ($rule['commission_amount'] ?? '0'), 8);
            if (bccomp($lineAmount, '0', 8) <= 0) {
                continue;
            }
            $weights = [];
            $reservedQuantity = '0';
            foreach ($item->reservations as $reservation) {
                $store = $reservation->balance?->location?->store;
                $key = $store?->uuid ?: '__unassigned__';
                $weights[$key] = ['branch' => $store ? ['id' => $store->uuid, 'name' => $store->name, 'code' => $store->code] : null, 'quantity' => bcadd($weights[$key]['quantity'] ?? '0', (string) $reservation->quantity, 8)];
                $reservedQuantity = bcadd($reservedQuantity, (string) $reservation->quantity, 8);
            }
            $remaining = bcsub((string) $item->quantity, $reservedQuantity, 8);
            if (bccomp($remaining, '0', 8) > 0 || $weights === []) {
                $weights['__unassigned__'] = ['branch' => null, 'quantity' => max('0', $remaining)];
            }
            $weightTotal = array_reduce($weights, fn (string $carry, array $weight): string => bcadd($carry, $weight['quantity'], 8), '0');
            foreach ($weights as $weight) {
                $parts[] = ['branch' => $weight['branch'], 'amount' => $this->proportionalAmount($lineAmount, $weight['quantity'], $weightTotal)];
            }
        }
        if ($parts === []) {
            return [['branch' => null, 'amount' => $this->money($entry->amount)]];
        }
        $total = array_reduce($parts, fn (string $carry, array $part): string => bcadd($carry, $part['amount'], 8), '0');
        $difference = bcsub((string) $entry->amount, $total, 8);
        $last = count($parts) - 1;
        if (bccomp($difference, '0', 8) !== 0) {
            $parts[$last]['amount'] = $this->money(bcadd($parts[$last]['amount'], $difference, 8));
        }

        return collect($parts)->groupBy(fn (array $part): string => $part['branch']['id'] ?? '__unassigned__')->map(fn (Collection $group): array => ['branch' => $group->first()['branch'], 'amount' => $this->money((string) $group->sum('amount'))])->values()->all();
    }

    private function filterAllocatedRows(Collection $rows, ?string $branchId): Collection
    {
        return $rows->filter(fn (array $row): bool => ! $branchId || ($row['branch']['id'] ?? null) === $branchId)->values();
    }

    private function proportionalAmount(string $amount, string $weight, string $total): string
    {
        return bccomp($total, '0', 8) === 0 ? '0.00' : $this->money(bcdiv(bcmul($amount, $weight, 8), $total, 8));
    }

    public function payoutSummaries(array $filters): array
    {
        if (! $this->storageAvailable()) {
            return ['configured' => false, 'provider' => 'not_configured', 'data' => []];
        }

        $rows = MarketplaceSettlementEntry::query()
            ->select('tenant_id', 'currency_code')
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'seller_earning' AND status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'seller_earning' AND status = 'available' AND NOT EXISTS (SELECT 1 FROM marketplace_payout_items INNER JOIN marketplace_payouts ON marketplace_payouts.id = marketplace_payout_items.marketplace_payout_id WHERE marketplace_payout_items.marketplace_settlement_entry_id = marketplace_settlement_entries.id AND marketplace_payouts.status IN ('requested', 'approved', 'processing')) THEN amount ELSE 0 END), 0) AS available_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'payout' AND direction = 'debit' AND status = 'available' THEN amount ELSE 0 END), 0) AS paid_amount")
            ->when($filters['tenant_id'] ?? null, fn (Builder $query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->groupBy('tenant_id', 'currency_code')
            ->with('tenant:id,name')
            ->paginate($filters['per_page'] ?? 25);

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
