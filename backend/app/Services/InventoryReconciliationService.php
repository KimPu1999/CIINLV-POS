<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InventoryReconciliationService
{
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
