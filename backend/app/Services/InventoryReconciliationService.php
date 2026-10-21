<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
    /**
     * Return aggregate reconciliation counts plus a bounded sample for monitoring.
     * The existing reconcile() method remains the complete report used by the
     * scheduled command and is intentionally unchanged.
     */
    public function summary(?int $tenantId = null, int $sampleLimit = 25): array
    {
        $ledger = DB::table('stock_movements')
            ->select('tenant_id', 'inventory_location_id', 'product_variant_id')
            ->selectRaw('COALESCE(SUM(quantity_delta), 0) AS ledger_quantity')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('tenant_id', 'inventory_location_id', 'product_variant_id');

        $base = DB::table('inventory_balances as balances')
            ->leftJoinSub($ledger, 'ledger', function ($join): void {
                $join->on('ledger.tenant_id', '=', 'balances.tenant_id')
                    ->on('ledger.inventory_location_id', '=', 'balances.inventory_location_id')
                    ->on('ledger.product_variant_id', '=', 'balances.product_variant_id');
            })
            ->when($tenantId !== null, fn ($query) => $query->where('balances.tenant_id', $tenantId));

        $discrepancy = 'balances.on_hand_quantity <> COALESCE(ledger.ledger_quantity, 0) OR balances.on_hand_quantity < 0 OR balances.reserved_quantity < 0 OR balances.reserved_quantity > balances.on_hand_quantity';
        $counts = (clone $base)
            ->selectRaw('COUNT(*) AS checked')
            ->selectRaw("SUM(CASE WHEN {$discrepancy} THEN 1 ELSE 0 END) AS discrepancy_count")
            ->first();
        $sample = (clone $base)
            ->whereRaw($discrepancy)
            ->select(['balances.tenant_id', 'balances.inventory_location_id', 'balances.product_variant_id', 'balances.on_hand_quantity', 'balances.reserved_quantity'])
            ->selectRaw('COALESCE(ledger.ledger_quantity, 0) AS ledger_quantity')
            ->limit(max(1, min($sampleLimit, 100)))
            ->get()
            ->map(fn (object $row): array => [
                'tenant_id' => (int) $row->tenant_id,
                'inventory_location_id' => (int) $row->inventory_location_id,
                'product_variant_id' => (int) $row->product_variant_id,
                'balance_quantity' => (string) $row->on_hand_quantity,
                'ledger_quantity' => (string) $row->ledger_quantity,
                'reserved_quantity' => (string) $row->reserved_quantity,
            ])->values()->all();

        return [
            'checked' => (int) ($counts?->checked ?? 0),
            'discrepancy_count' => (int) ($counts?->discrepancy_count ?? 0),
            'sample' => $sample,
        ];
    }

    /**
     * Compares the materialized balance to the append-only movement ledger.
     * This is report-only by design: corrections must use normal stock services.
     *
     * @return array{checked: int, discrepancies: array<int, array<string, mixed>>}
     */
    public function reconcile(?int $tenantId = null): array
    {
        $ledger = DB::table('stock_movements')
            ->select('tenant_id', 'inventory_location_id', 'product_variant_id')
            ->selectRaw('COALESCE(SUM(quantity_delta), 0) AS ledger_quantity')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->groupBy('tenant_id', 'inventory_location_id', 'product_variant_id');

        $rows = DB::table('inventory_balances as balances')
            ->leftJoinSub($ledger, 'ledger', function ($join): void {
                $join->on('ledger.tenant_id', '=', 'balances.tenant_id')
                    ->on('ledger.inventory_location_id', '=', 'balances.inventory_location_id')
                    ->on('ledger.product_variant_id', '=', 'balances.product_variant_id');
            })
            ->when($tenantId !== null, fn ($query) => $query->where('balances.tenant_id', $tenantId))
            ->select([
                'balances.tenant_id',
                'balances.inventory_location_id',
                'balances.product_variant_id',
                'balances.on_hand_quantity',
                'balances.reserved_quantity',
                DB::raw('COALESCE(ledger.ledger_quantity, 0) AS ledger_quantity'),
            ])
            ->get();

        $discrepancies = [];
        foreach ($rows as $row) {
            $balance = (string) $row->on_hand_quantity;
            $ledgerQuantity = (string) $row->ledger_quantity;
            $reserved = (string) $row->reserved_quantity;

            if (bccomp($balance, $ledgerQuantity, 3) !== 0 || bccomp($balance, '0', 3) < 0 || bccomp($reserved, '0', 3) < 0 || bccomp($reserved, $balance, 3) > 0) {
                $discrepancies[] = [
                    'tenant_id' => (int) $row->tenant_id,
                    'inventory_location_id' => (int) $row->inventory_location_id,
                    'product_variant_id' => (int) $row->product_variant_id,
                    'balance_quantity' => $balance,
                    'ledger_quantity' => $ledgerQuantity,
                    'reserved_quantity' => $reserved,
                ];
            }
        }

        return ['checked' => $rows->count(), 'discrepancies' => $discrepancies];
    }
}
