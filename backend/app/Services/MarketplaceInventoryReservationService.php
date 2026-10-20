<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\MarketplaceInventoryReservation;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceInventoryReservationService
{
    public function reserve(MarketplaceOrder $order, MarketplaceOrderItem $item, ProductVariant $variant, string $quantity, int $ttlMinutes = 1440): void
    {
        if (! $variant->product?->track_inventory) {
            return;
        }

        $remaining = bcadd($quantity, '0', 3);
        $balances = InventoryBalance::where('tenant_id', $variant->tenant_id)
            ->where('product_variant_id', $variant->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($balances as $balance) {
            $available = bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            if (bccomp($available, '0', 3) <= 0) {
                continue;
            }
            $reserved = bccomp($available, $remaining, 3) >= 0 ? $remaining : $available;
            $balance->update([
                'reserved_quantity' => bcadd((string) $balance->reserved_quantity, $reserved, 3),
                'version' => $balance->version + 1,
            ]);
            MarketplaceInventoryReservation::create([
                'marketplace_order_id' => $order->id,
                'marketplace_order_item_id' => $item->id,
                'tenant_id' => $variant->tenant_id,
                'inventory_balance_id' => $balance->id,
                'inventory_location_id' => $balance->inventory_location_id,
                'product_variant_id' => $variant->id,
                'quantity' => $reserved,
                'status' => 'reserved',
                'idempotency_key' => $order->uuid.':'.$item->uuid.':'.$balance->id,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
            $remaining = bcsub($remaining, $reserved, 3);
            if (bccomp($remaining, '0', 3) <= 0) {
                return;
            }
        }

        throw new UnprocessableEntityHttpException('Insufficient available stock for '.$variant->sku.'.');
    }

    public function release(MarketplaceInventoryReservation $reservation, string $status = 'released'): void
    {
        DB::transaction(function () use ($reservation, $status): void {
            $locked = MarketplaceInventoryReservation::whereKey($reservation->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'reserved') {
                return;
            }
            $balance = InventoryBalance::whereKey($locked->inventory_balance_id)->lockForUpdate()->first();
            if ($balance) {
                $next = bcsub((string) $balance->reserved_quantity, (string) $locked->quantity, 3);
                if (bccomp($next, '0', 3) < 0) {
                    throw new UnprocessableEntityHttpException('The inventory reservation is inconsistent.');
                }
                $balance->update(['reserved_quantity' => $next, 'version' => $balance->version + 1]);
            }
            $locked->update(['status' => $status, 'released_at' => now()]);
        });
    }

    public function releaseExpired(): int
    {
        $released = 0;
        MarketplaceInventoryReservation::where('status', 'reserved')->where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($reservations) use (&$released): void {
            foreach ($reservations as $reservation) {
                $this->release($reservation, 'expired');
                $released++;
            }
        });

        return $released;
    }
}
