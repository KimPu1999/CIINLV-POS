<?php

namespace App\Services;

use App\Events\LowStockResolved;
use App\Events\LowStockTriggered;
use App\Jobs\SendStockAlert;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockAlertService
{
    public function evaluate(InventoryLocation $location, ProductVariant $variant): ?StockAlert
    {
        return DB::transaction(function () use ($location, $variant): ?StockAlert {
            $balance = InventoryBalance::where('tenant_id', $location->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->lockForUpdate()->first();
            if (! $balance) {
                return null;
            }
            $available = bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            $threshold = (string) ($variant->reorder_level ?? 0);
            $type = bccomp($available, '0', 3) <= 0 ? 'out_of_stock' : (bccomp($available, $threshold, 3) <= 0 ? 'low_stock' : null);
            $active = StockAlert::where('tenant_id', $location->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->whereIn('status', ['open', 'acknowledged'])->first();

            if ($type === null) {
                if ($active) {
                    $active->update(['status' => 'resolved', 'resolved_at' => now(), 'available_quantity' => $available]);
                    event(new LowStockResolved($active));
                }

                return null;
            }

            if ($active) {
                $active->update(['alert_type' => $type, 'available_quantity' => $available, 'threshold_quantity' => $threshold, 'last_triggered_at' => now()]);

                return $active;
            }

            $alert = StockAlert::where('tenant_id', $location->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->where('status', 'resolved')->latest('resolved_at')->first();
            if ($alert) {
                $alert->update(['alert_type' => $type, 'threshold_quantity' => $threshold, 'available_quantity' => $available, 'status' => 'open', 'first_triggered_at' => now(), 'last_triggered_at' => now(), 'resolved_at' => null]);
            } else {
                $alert = StockAlert::create(['tenant_id' => $location->tenant_id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'alert_type' => $type, 'threshold_quantity' => $threshold, 'available_quantity' => $available, 'status' => 'open', 'first_triggered_at' => now(), 'last_triggered_at' => now(), 'metadata' => ['source' => 'inventory_balance']]);
            }
            event(new LowStockTriggered($alert));
            SendStockAlert::dispatch($alert->id)->afterCommit();

            return $alert;
        });
    }

    public function acknowledge(StockAlert $alert, User $user): StockAlert
    {
        $alert->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $user->id]);

        return $alert->refresh();
    }

    public function notify(StockAlert $alert): void
    {
        app(AuditLogger::class)->record('stock_alert.notified', null, $alert->tenant, $alert, ['alert_type' => $alert->alert_type, 'location_id' => $alert->inventory_location_id, 'variant_id' => $alert->product_variant_id]);
    }
}
