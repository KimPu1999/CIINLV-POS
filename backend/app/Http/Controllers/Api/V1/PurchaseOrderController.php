<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreatePurchaseOrderRequest;
use App\Http\Requests\Inventory\ReceivePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StoreMembership;
use App\Models\Supplier;
use App\Services\AuditLogger;
use App\Services\GoodsReceivingService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PurchaseOrderController
{
    public function index(TenantContext $context, Request $request): JsonResponse
    {
        $orders = $this->filteredOrders($request, $context->tenant()->id)->with(['supplier', 'destination.store', 'items.variant.product'])->withCount('items')->latest('purchase_orders.created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json(['success' => true, 'data' => PurchaseOrderResource::collection($orders), 'meta' => ['total' => $orders->total(), 'per_page' => $orders->perPage(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }

    public function statistics(TenantContext $context, Request $request): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $locations = $this->accessibleLocationIds($request->user(), $tenantId);
        $orders = $this->filteredOrders($request, $context->tenant()->id);
        $value = (clone $orders)->whereNotIn('status', ['cancelled'])->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')->sum(DB::raw('purchase_order_items.ordered_quantity * purchase_order_items.unit_cost'));
        $month = (clone $orders)->whereBetween('ordered_at', [now()->startOfMonth(), now()->endOfMonth()])->whereNotIn('status', ['cancelled', 'draft'])->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')->sum(DB::raw('purchase_order_items.ordered_quantity * purchase_order_items.unit_cost'));

        return response()->json(['success' => true, 'data' => ['total_orders' => (clone $orders)->count(), 'draft_orders' => (clone $orders)->where('status', 'draft')->count(), 'pending_approval' => (clone $orders)->where('status', 'submitted')->count(), 'approved_orders' => (clone $orders)->where('status', 'approved')->count(), 'partially_received_orders' => (clone $orders)->where('status', 'partially_received')->count(), 'received_orders' => (clone $orders)->where('status', 'received')->count(), 'cancelled_orders' => (clone $orders)->where('status', 'cancelled')->count(), 'outstanding_order_value' => number_format((float) $value, 2, '.', ''), 'purchases_this_month' => number_format((float) $month, 2, '.', ''), 'payments_available' => false]]);
    }

    public function store(CreatePurchaseOrderRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $supplier = Supplier::where('tenant_id', $context->tenant()->id)->where('uuid', $data['supplier_id'])->firstOrFail();
        $location = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $data['destination_location_id'])->where('status', 'active')->firstOrFail();
        Gate::forUser($request->user())->authorize('write', $location);
        $order = DB::transaction(function () use ($data, $context, $request, $supplier, $location): PurchaseOrder {
            if (PurchaseOrder::where('tenant_id', $context->tenant()->id)->where('purchase_order_number', $data['purchase_order_number'])->exists()) {
                throw new ConflictHttpException('The purchase order number is already in use.');
            } $order = PurchaseOrder::create(['tenant_id' => $context->tenant()->id, 'supplier_id' => $supplier->id, 'destination_location_id' => $location->id, 'purchase_order_number' => $data['purchase_order_number'], 'status' => 'draft', 'created_by' => $request->user()->id]);
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::where('tenant_id', $context->tenant()->id)->where('uuid', $item['variant_id'])->where('status', 'active')->firstOrFail();
                $order->items()->create(['product_variant_id' => $variant->id, 'ordered_quantity' => $item['ordered_quantity'], 'unit_cost' => $item['unit_cost']]);
            }

            return $order;
        });
        $auditLogger->record('inventory.purchase_order_created', $request->user(), $context->tenant(), $order);

        return response()->json(['success' => true, 'data' => new PurchaseOrderResource($order->load(['supplier', 'destination', 'items.variant.product']))], 201);
    }

    public function show(PurchaseOrder $order, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertTenant($order, $context);
        Gate::authorize('view', $order);
        Gate::forUser($request->user())->authorize('view', $order->destination);

        return response()->json(['success' => true, 'data' => new PurchaseOrderResource($order->load(['supplier', 'destination', 'items.variant.product', 'receipts.items']))]);
    }

    public function submit(PurchaseOrder $order, TenantContext $context, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($order, $context);
        Gate::authorize('manage', $order);
        Gate::forUser($request->user())->authorize('write', $order->destination);
        if ($order->status !== 'draft') {
            throw new ConflictHttpException('Only draft purchase orders can be submitted.');
        } $order->update(['status' => 'submitted', 'ordered_at' => now()]);
        $auditLogger->record('inventory.purchase_order_submitted', $request->user(), $context->tenant(), $order);

        return response()->json(['success' => true, 'data' => new PurchaseOrderResource($order->refresh())]);
    }

    public function approve(PurchaseOrder $order, TenantContext $context, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($order, $context);
        Gate::authorize('manage', $order);
        if ($order->status !== 'submitted') {
            throw new ConflictHttpException('Only submitted purchase orders can be approved.');
        }
        $order->update(['status' => 'approved']);
        $auditLogger->record('inventory.purchase_order_approved', $request->user(), $context->tenant(), $order);

        return response()->json(['success' => true, 'data' => new PurchaseOrderResource($order->refresh())]);
    }

    public function cancel(PurchaseOrder $order, TenantContext $context, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($order, $context);
        Gate::authorize('manage', $order);
        if (! in_array($order->status, ['draft', 'submitted', 'approved'], true)) {
            throw new ConflictHttpException('A received purchase order cannot be cancelled.');
        }
        $order->update(['status' => 'cancelled']);
        $auditLogger->record('inventory.purchase_order_cancelled', $request->user(), $context->tenant(), $order);

        return response()->json(['success' => true, 'data' => new PurchaseOrderResource($order->refresh())]);
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $order, TenantContext $context, GoodsReceivingService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($order, $context);
        Gate::authorize('manage', $order);
        Gate::forUser($request->user())->authorize('write', $order->destination);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required for goods receiving.');
        } $receipt = $service->receive($order, $request->validated()['items'], $request->user(), $key);
        $auditLogger->record('inventory.goods_received', $request->user(), $context->tenant(), $receipt, ['purchase_order_id' => $order->uuid]);

        return response()->json(['success' => true, 'data' => $receipt], 201);
    }

    private function filteredOrders(Request $request, int $tenantId)
    {
        $locationIds = $this->accessibleLocationIds($request->user(), $tenantId);
        $query = PurchaseOrder::query()->where('purchase_orders.tenant_id', $tenantId)->whereIn('purchase_orders.destination_location_id', $locationIds);

        if ($request->filled('branch_id')) {
            $query->whereHas('destination.store', fn ($store) => $store->where('stores.uuid', $request->string('branch_id')));
        }
        if ($request->filled('destination_location_id')) {
            $query->whereHas('destination', fn ($location) => $location->where('inventory_locations.uuid', $request->string('destination_location_id')));
        }
        if ($request->filled('supplier_id')) {
            $query->whereHas('supplier', fn ($supplier) => $supplier->where('suppliers.uuid', $request->string('supplier_id')));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('purchase_orders.ordered_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('purchase_orders.ordered_at', '<=', $request->date('date_to'));
        }
        if ($request->filled('search')) {
            $term = $request->string('search');
            $query->where(fn ($inner) => $inner->where('purchase_orders.purchase_order_number', 'ilike', '%'.$term.'%')->orWhereHas('supplier', fn ($supplier) => $supplier->where('suppliers.name', 'ilike', '%'.$term.'%')->orWhere('suppliers.supplier_code', 'ilike', '%'.$term.'%'))->orWhereHas('items.variant', fn ($variant) => $variant->where('sku', 'ilike', '%'.$term.'%')));
        }
        if ($request->filled('status')) {
            $query->where('purchase_orders.status', $request->string('status'));
        }

        return $query;
    }

    private function assertTenant(PurchaseOrder $order, TenantContext $context): void
    {
        abort_unless((int) $order->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function requireManager($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }

    private function accessibleLocationIds($user, int $tenantId): Collection
    {
        $role = $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->with('role')->first()?->role?->name;
        if ($role === 'business_admin') {
            return InventoryLocation::where('tenant_id', $tenantId)->pluck('id');
        }
        $storeIds = StoreMembership::where('tenant_id', $tenantId)->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id');

        return InventoryLocation::where('tenant_id', $tenantId)->where(function ($query) use ($role, $storeIds): void {
            $query->whereIn('store_id', $storeIds);
            if ($role === 'inventory_manager') {
                $query->orWhere('location_type', 'warehouse');
            }
        })->pluck('id');
    }
}
