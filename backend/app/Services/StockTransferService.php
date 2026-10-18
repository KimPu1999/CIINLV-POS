<?php

namespace App\Services;

use App\Events\StockTransferDispatched;
use App\Events\StockTransferReceived;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class StockTransferService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function submit(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->with('items.variant', 'source', 'destination')->firstOrFail();
            if ($transfer->status !== 'draft') {
                throw new ConflictHttpException('Only draft transfers can be submitted.');
            }
            foreach ($transfer->items->sortBy('product_variant_id') as $item) {
                $this->inventoryService->reserve($transfer->source, $item->variant, (string) $item->requested_quantity);
                $item->update(['reserved_quantity' => $item->requested_quantity]);
            }
            $transfer->update(['status' => 'pending_approval']);

            return $transfer->fresh('items.variant');
        });
    }

    public function approve(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== 'pending_approval') {
            throw new ConflictHttpException('Only pending transfers can be approved.');
        } $transfer->update(['status' => 'approved']);

        return $transfer->fresh('items.variant');
    }

    public function dispatch(StockTransfer $transfer, User $actor): StockTransfer
    {
        $dispatched = DB::transaction(function () use ($transfer, $actor): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->with('items.variant', 'source')->firstOrFail();
            if ($transfer->status !== 'approved') {
                throw new ConflictHttpException('Only approved transfers can be dispatched.');
            }
            foreach ($transfer->items->sortBy('product_variant_id') as $item) {
                $this->inventoryService->dispatchReserved($transfer->source, $item->variant, (string) $item->reserved_quantity, $actor, 'stock_transfer', $transfer->uuid, 'transfer:'.$transfer->uuid.':out:'.$item->variant->id);
                $item->update(['dispatched_quantity' => $item->reserved_quantity]);
            }
            $transfer->update(['status' => 'dispatched']);

            return $transfer->fresh('items.variant');
        });
        event(new StockTransferDispatched($dispatched->loadMissing('tenant')));

        return $dispatched;
    }

    public function receive(StockTransfer $transfer, array $items, User $actor, string $idempotencyKey): StockTransfer
    {
        $received = DB::transaction(function () use ($transfer, $items, $actor, $idempotencyKey): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->with('items.variant', 'destination')->firstOrFail();
            $existingReceipt = StockTransferReceipt::where('tenant_id', $transfer->tenant_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingReceipt) {
                if ((int) $existingReceipt->stock_transfer_id !== (int) $transfer->id) {
                    throw new ConflictHttpException('This idempotency key was already used for another transfer.');
                }

                return $transfer;
            }
            if (! in_array($transfer->status, ['dispatched', 'partially_received'], true)) {
                throw new ConflictHttpException('This transfer cannot receive goods in its current state.');
            }

            StockTransferReceipt::create([
                'tenant_id' => $transfer->tenant_id,
                'stock_transfer_id' => $transfer->id,
                'received_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'payload' => $items,
            ]);
            foreach ($items as $data) {
                $variant = ProductVariant::where('tenant_id', $transfer->tenant_id)->where('uuid', $data['variant_id'])->firstOrFail();
                $item = $transfer->items->firstWhere('product_variant_id', $variant->id);
                if (! $item) {
                    throw new UnprocessableEntityHttpException('The received variant is not on this transfer.');
                }
                $received = (string) ($data['received_quantity'] ?? 0);
                $damaged = (string) ($data['damaged_quantity'] ?? 0);
                $missing = (string) ($data['missing_quantity'] ?? 0);
                $outstanding = bcsub((string) $item->dispatched_quantity, bcadd(bcadd((string) $item->received_quantity, (string) $item->damaged_quantity, 3), (string) $item->missing_quantity, 3), 3);
                if (bccomp(bcadd(bcadd($received, $damaged, 3), $missing, 3), $outstanding, 3) > 0) {
                    throw new UnprocessableEntityHttpException('The received quantities exceed the dispatched transfer quantity.');
                }
                if (bccomp($received, '0', 3) > 0) {
                    $this->inventoryService->mutate($transfer->destination, $variant, 'stock_transfer_in', $received, $actor, 'stock_transfer', $transfer->uuid, null, $idempotencyKey.':'.$variant->id);
                }
                $item->update(['received_quantity' => bcadd((string) $item->received_quantity, $received, 3), 'damaged_quantity' => bcadd((string) $item->damaged_quantity, $damaged, 3), 'missing_quantity' => bcadd((string) $item->missing_quantity, $missing, 3)]);
            }
            $complete = $transfer->items()->whereRaw('received_quantity + damaged_quantity + missing_quantity < dispatched_quantity')->doesntExist();
            $transfer->update(['status' => $complete ? 'received' : 'partially_received']);

            return $transfer->fresh('items.variant');
        });
        event(new StockTransferReceived($received->loadMissing('tenant')));

        return $received;
    }

    public function cancel(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $transfer = StockTransfer::whereKey($transfer->id)->lockForUpdate()->with('items.variant', 'source')->firstOrFail();
            if (in_array($transfer->status, ['dispatched', 'partially_received', 'received', 'cancelled'], true)) {
                throw new ConflictHttpException('This transfer cannot be cancelled after dispatch.');
            } foreach ($transfer->items as $item) {
                if (bccomp((string) $item->reserved_quantity, '0', 3) > 0) {
                    $this->inventoryService->releaseReservation($transfer->source, $item->variant, (string) $item->reserved_quantity);
                }
            } $transfer->update(['status' => 'cancelled']);

            return $transfer->fresh('items.variant');
        });
    }

    public function assertLocationPair(InventoryLocation $source, InventoryLocation $destination, int $tenantId): void
    {
        if ((int) $source->tenant_id !== $tenantId || (int) $destination->tenant_id !== $tenantId || $source->id === $destination->id || $source->status !== 'active' || $destination->status !== 'active') {
            throw new UnprocessableEntityHttpException('Both transfer locations must be active locations in this business and must differ.');
        }
    }
}
