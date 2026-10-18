<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class GoodsReceivingService
{
    public function __construct(private readonly InventoryService $inventoryService) {}

    public function receive(PurchaseOrder $order, array $items, User $actor, string $idempotencyKey): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $items, $actor, $idempotencyKey): GoodsReceipt {
            $order = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($existing = GoodsReceipt::where('tenant_id', $order->tenant_id)->where('idempotency_key', $idempotencyKey)->with('items')->first()) {
                return $existing;
            }
            if (! in_array($order->status, ['submitted', 'approved', 'partially_received'], true)) {
                throw new ConflictHttpException('This purchase order cannot receive goods in its current state.');
            }
            $receipt = GoodsReceipt::create(['tenant_id' => $order->tenant_id, 'purchase_order_id' => $order->id, 'received_by' => $actor->id, 'status' => 'posted', 'idempotency_key' => $idempotencyKey]);
            $orderItems = $order->items()->lockForUpdate()->get()->keyBy('product_variant_id');
            foreach ($items as $data) {
                $variant = ProductVariant::where('tenant_id', $order->tenant_id)->where('uuid', $data['variant_id'])->firstOrFail();
                $orderItem = $orderItems->get($variant->id);
                if (! $orderItem) {
                    throw new UnprocessableEntityHttpException('The received variant is not on this purchase order.');
                }
                $quantity = (string) $data['quantity_received'];
                $remaining = bcsub((string) $orderItem->ordered_quantity, (string) $orderItem->received_quantity, 3);
                if (bccomp($quantity, $remaining, 3) > 0) {
                    throw new UnprocessableEntityHttpException('Received quantity exceeds the outstanding purchase quantity.');
                }
                $receipt->items()->create(['product_variant_id' => $variant->id, 'quantity_received' => $quantity, 'unit_cost' => $data['unit_cost'] ?? $orderItem->unit_cost]);
                $this->inventoryService->mutate($order->destination, $variant, 'purchase_receipt', $quantity, $actor, 'goods_receipt', $receipt->uuid, null, $idempotencyKey.':'.$variant->id, (string) ($data['unit_cost'] ?? $orderItem->unit_cost));
                $orderItem->update(['received_quantity' => bcadd((string) $orderItem->received_quantity, $quantity, 3)]);
            }
            $allReceived = $order->items()->whereColumn('received_quantity', '<', 'ordered_quantity')->doesntExist();
            $order->update(['status' => $allReceived ? 'received' : 'partially_received']);

            return $receipt->load('items');
        });
    }
}
