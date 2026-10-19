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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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
            $active = StockAlert::where('tenant_id', $location->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->whereIn('status', ['open', 'acknowledged', 'snoozed'])->first();

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
        if ($alert->status === 'resolved') {
            throw new ConflictHttpException('A resolved stock alert cannot be acknowledged.');
        }
        $alert->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $user->id, 'snoozed_until' => null, 'snoozed_by' => null]);

        return $alert->refresh();
    }

    public function snooze(StockAlert $alert, User $user, int $hours): StockAlert
    {
        if ($alert->status === 'resolved') {
            throw new ConflictHttpException('A resolved stock alert cannot be snoozed.');
        }
        $alert->update(['status' => 'snoozed', 'snoozed_until' => now()->addHours($hours), 'snoozed_by' => $user->id]);

        return $alert->refresh();
    }

    public function reopen(StockAlert $alert): StockAlert
    {
        if ($alert->status !== 'resolved') {
            throw new ConflictHttpException('Only resolved stock alerts can be reopened.');
        }
        $alert->update(['status' => 'open', 'resolved_at' => null, 'last_triggered_at' => now()]);

        return $alert->refresh();
    }

    /** @return array{incoming_quantity: string, target_quantity: string, recommended_quantity: string} */
    public function replenishment(StockAlert $alert): array
    {
        $tenantId = (int) $alert->tenant_id;
        $locationId = (int) $alert->inventory_location_id;
        $variantId = (int) $alert->product_variant_id;
        $incomingPurchaseOrders = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.tenant_id', $tenantId)
            ->where('purchase_orders.destination_location_id', $locationId)
            ->where('purchase_order_items.product_variant_id', $variantId)
            ->whereIn('purchase_orders.status', ['approved', 'partially_received'])
            ->sum(DB::raw('purchase_order_items.ordered_quantity - purchase_order_items.received_quantity'));
        $incomingTransfers = DB::table('stock_transfer_items')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')
            ->where('stock_transfers.tenant_id', $tenantId)
            ->where('stock_transfers.destination_location_id', $locationId)
            ->where('stock_transfer_items.product_variant_id', $variantId)
            ->whereIn('stock_transfers.status', ['dispatched', 'partially_received'])
            ->sum(DB::raw('stock_transfer_items.dispatched_quantity - stock_transfer_items.received_quantity'));
        $incoming = max(0, (float) $incomingPurchaseOrders + (float) $incomingTransfers);
        $target = max(0, (float) $alert->threshold_quantity);
        $recommended = max(0, $target - (float) $alert->available_quantity - $incoming);

        return ['incoming_quantity' => number_format($incoming, 3, '.', ''), 'target_quantity' => number_format($target, 3, '.', ''), 'recommended_quantity' => number_format($recommended, 3, '.', '')];
    }

    public function notify(StockAlert $alert): void
    {
        app(AuditLogger::class)->record('stock_alert.notified', null, $alert->tenant, $alert, ['alert_type' => $alert->alert_type, 'location_id' => $alert->inventory_location_id, 'variant_id' => $alert->product_variant_id]);
    }
}
