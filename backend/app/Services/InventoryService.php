<?php

namespace App\Services;

use App\Events\StockUpdated;
use App\Jobs\EvaluateLowStock;
use App\Jobs\RefreshReportingCache;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InventoryService
{
    public function setQuantity(InventoryLocation $location, ProductVariant $variant, string $quantity, User $actor, ?string $referenceType = null, ?string $referenceId = null, ?string $reason = null, ?string $idempotencyKey = null, ?string $unitCost = null): ?StockMovement
    {
        $movement = DB::transaction(function () use ($location, $variant, $quantity, $actor, $referenceType, $referenceId, $reason, $idempotencyKey, $unitCost): ?StockMovement {
            $this->assertPair($location, $variant);
            if ($idempotencyKey && ($existing = StockMovement::where('tenant_id', $location->tenant_id)->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $target = bcadd($quantity, '0', 3);
            if (bccomp($target, '0', 3) < 0) {
                throw new UnprocessableEntityHttpException('The inventory quantity cannot be negative.');
            }
            $balance = $this->lockedBalance($location, $variant);
            $before = (string) $balance->on_hand_quantity;
            if (bccomp($target, (string) $balance->reserved_quantity, 3) < 0) {
                throw new UnprocessableEntityHttpException('The inventory quantity cannot be lower than reserved stock.');
            }
            $delta = bcsub($target, $before, 3);
            if (bccomp($delta, '0', 3) === 0) {
                return null;
            }
            $average = $this->weightedAverage($before, (string) $balance->average_cost, $delta, $unitCost, $target);
            $balance->update(['on_hand_quantity' => $target, 'average_cost' => $average, 'version' => $balance->version + 1]);
            $movementType = bccomp($delta, '0', 3) > 0 ? 'adjustment_in' : 'adjustment_out';

            return StockMovement::create(['tenant_id' => $location->tenant_id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'movement_type' => $movementType, 'quantity_delta' => $delta, 'quantity_before' => $before, 'quantity_after' => $target, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'reason' => $reason, 'actor_user_id' => $actor->id, 'idempotency_key' => $idempotencyKey]);
        });
        if ($movement) {
            event(new StockUpdated($location->loadMissing(['tenant', 'store']), $variant, (string) $movement->quantity_after));
            EvaluateLowStock::dispatch($location->id, $variant->id)->afterCommit();
            RefreshReportingCache::dispatch((int) $location->tenant_id)->afterCommit();
        }

        return $movement;
    }

    public function mutate(InventoryLocation $location, ProductVariant $variant, string $type, string $delta, User $actor, ?string $referenceType = null, ?string $referenceId = null, ?string $reason = null, ?string $idempotencyKey = null, ?string $unitCost = null): StockMovement
    {
        $movement = DB::transaction(function () use ($location, $variant, $type, $delta, $actor, $referenceType, $referenceId, $reason, $idempotencyKey, $unitCost): StockMovement {
            $this->assertPair($location, $variant);
            if ($idempotencyKey && ($existing = StockMovement::where('tenant_id', $location->tenant_id)->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $balance = $this->lockedBalance($location, $variant);
            $before = (string) $balance->on_hand_quantity;
            $after = bcadd($before, $delta, 3);
            if (bccomp($after, '0', 3) < 0 || bccomp($after, (string) $balance->reserved_quantity, 3) < 0) {
                throw new UnprocessableEntityHttpException('The inventory change would create negative or reserved stock.');
            }
            $average = $this->weightedAverage($before, (string) $balance->average_cost, $delta, $unitCost, $after);
            $balance->update(['on_hand_quantity' => $after, 'average_cost' => $average, 'version' => $balance->version + 1]);

            return StockMovement::create(['tenant_id' => $location->tenant_id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'movement_type' => $type, 'quantity_delta' => $delta, 'quantity_before' => $before, 'quantity_after' => $after, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'reason' => $reason, 'actor_user_id' => $actor->id, 'idempotency_key' => $idempotencyKey]);
        });
        event(new StockUpdated($location->loadMissing(['tenant', 'store']), $variant, (string) $movement->quantity_after));
        EvaluateLowStock::dispatch($location->id, $variant->id)->afterCommit();
        RefreshReportingCache::dispatch((int) $location->tenant_id)->afterCommit();

        return $movement;
    }

    public function reserve(InventoryLocation $location, ProductVariant $variant, string $quantity): void
    {
        DB::transaction(function () use ($location, $variant, $quantity): void {
            $this->assertPair($location, $variant);
            $balance = $this->lockedBalance($location, $variant);
            $available = bcsub((string) $balance->on_hand_quantity, (string) $balance->reserved_quantity, 3);
            if (bccomp($available, $quantity, 3) < 0) {
                throw new UnprocessableEntityHttpException('Insufficient available stock for this reservation.');
            }
            $balance->update(['reserved_quantity' => bcadd((string) $balance->reserved_quantity, $quantity, 3), 'version' => $balance->version + 1]);
        });
    }

    public function releaseReservation(InventoryLocation $location, ProductVariant $variant, string $quantity): void
    {
        DB::transaction(function () use ($location, $variant, $quantity): void {
            $balance = $this->lockedBalance($location, $variant);
            if (bccomp((string) $balance->reserved_quantity, $quantity, 3) < 0) {
                throw new ConflictHttpException('The reservation exceeds the current reserved quantity.');
            }
            $balance->update(['reserved_quantity' => bcsub((string) $balance->reserved_quantity, $quantity, 3), 'version' => $balance->version + 1]);
        });
    }

    public function dispatchReserved(InventoryLocation $location, ProductVariant $variant, string $quantity, User $actor, string $referenceType, string $referenceId, ?string $idempotencyKey = null): StockMovement
    {
        return DB::transaction(function () use ($location, $variant, $quantity, $actor, $referenceType, $referenceId, $idempotencyKey): StockMovement {
            $this->assertPair($location, $variant);
            if ($idempotencyKey && ($existing = StockMovement::where('tenant_id', $location->tenant_id)->where('idempotency_key', $idempotencyKey)->first())) {
                return $existing;
            }
            $balance = $this->lockedBalance($location, $variant);
            if (bccomp((string) $balance->reserved_quantity, $quantity, 3) < 0) {
                throw new UnprocessableEntityHttpException('The dispatch exceeds the reserved stock.');
            }
            $before = (string) $balance->on_hand_quantity;
            $after = bcsub($before, $quantity, 3);
            $balance->update(['on_hand_quantity' => $after, 'reserved_quantity' => bcsub((string) $balance->reserved_quantity, $quantity, 3), 'version' => $balance->version + 1]);

            return StockMovement::create(['tenant_id' => $location->tenant_id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'movement_type' => 'stock_transfer_out', 'quantity_delta' => '-'.$quantity, 'quantity_before' => $before, 'quantity_after' => $after, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'actor_user_id' => $actor->id, 'idempotency_key' => $idempotencyKey]);
        });
    }

    public function balance(InventoryLocation $location, ProductVariant $variant): InventoryBalance
    {
        return $this->lockedBalance($location, $variant, false);
    }

    private function lockedBalance(InventoryLocation $location, ProductVariant $variant, bool $lock = true): InventoryBalance
    {
        $query = InventoryBalance::where('tenant_id', $location->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        $balance = $query->first();
        if (! $balance) {
            DB::table('inventory_balances')->insertOrIgnore(['tenant_id' => $location->tenant_id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 0, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $balance = InventoryBalance::where('tenant_id', $location->tenant_id)->where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->lockForUpdate()->firstOrFail();
        }

        return $balance;
    }

    private function assertPair(InventoryLocation $location, ProductVariant $variant): void
    {
        if ((int) $location->tenant_id !== (int) $variant->tenant_id) {
            throw new UnprocessableEntityHttpException('The inventory location and product must belong to the same business.');
        }
    }

    private function weightedAverage(string $before, string $average, string $delta, ?string $unitCost, string $after): string
    {
        if ($unitCost === null || bccomp($delta, '0', 3) <= 0 || bccomp($after, '0', 3) <= 0) {
            return bcadd($average, '0', 4);
        }
        $total = bcadd(bcmul($before, $average, 8), bcmul($delta, $unitCost, 8), 8);

        return bcdiv($total, $after, 4);
    }
}
