<?php

namespace App\Console\Commands;

use App\Services\InventoryReconciliationService;
use Illuminate\Console\Command;

class InventoryReconcile extends Command
{
    protected $signature = 'ciinlv:inventory-reconcile {--tenant= : Internal tenant id to check}';

    protected $description = 'Check inventory balances against the append-only stock movement ledger.';

    public function handle(InventoryReconciliationService $reconciliation): int
    {
        $tenant = $this->option('tenant');
        $result = $reconciliation->reconcile($tenant === null ? null : (int) $tenant);

        $this->info("Checked {$result['checked']} inventory balances.");
        if ($result['discrepancies'] === []) {
            $this->info('No inventory discrepancies found.');

            return self::SUCCESS;
        }

        $this->error(count($result['discrepancies']).' discrepancy(s) found.');
        $this->table(['tenant_id', 'location_id', 'variant_id', 'balance', 'ledger', 'reserved'], array_map(static fn (array $row): array => [
            $row['tenant_id'], $row['inventory_location_id'], $row['product_variant_id'], $row['balance_quantity'], $row['ledger_quantity'], $row['reserved_quantity'],
        ], $result['discrepancies']));

        return self::FAILURE;
    }
}
