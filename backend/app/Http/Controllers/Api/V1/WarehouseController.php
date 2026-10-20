<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreateWarehouseRequest;
use App\Http\Requests\Inventory\UpdateWarehouseRequest;
use App\Http\Resources\InventoryLocationResource;
use App\Models\InventoryLocation;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\PurchaseOrder;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WarehouseController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $warehouses = InventoryLocation::query()->where('inventory_locations.tenant_id', $context->tenant()->id)->where('location_type', 'warehouse')->with('store')->withCount('balances')->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'ilike', '%'.$request->string('search').'%')->orWhere('code', 'ilike', '%'.$request->string('search').'%')))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderBy('name')->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json(['success' => true, 'data' => InventoryLocationResource::collection($warehouses), 'meta' => ['total' => $warehouses->total(), 'current_page' => $warehouses->currentPage(), 'last_page' => $warehouses->lastPage()]]);
    }

    public function statistics(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $this->requireManager($request->user(), $tenantId);
        $warehouseIds = InventoryLocation::where('tenant_id', $tenantId)->where('location_type', 'warehouse')->pluck('id');
        $balances = InventoryBalance::query()->where('inventory_balances.tenant_id', $tenantId)->whereIn('inventory_location_id', $warehouseIds);
        $lowStock = (clone $balances)->join('product_variants', function ($join): void { $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id'); })->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')->count();
        $outOfStock = (clone $balances)->whereRaw('(on_hand_quantity - reserved_quantity) <= 0')->count();
        $stock = (clone $balances)->selectRaw('COALESCE(SUM(on_hand_quantity), 0) AS units, COALESCE(SUM(on_hand_quantity * average_cost), 0) AS value')->first();

        return response()->json(['success' => true, 'data' => ['total_warehouses' => InventoryLocation::where('tenant_id', $tenantId)->where('location_type', 'warehouse')->count(), 'active_warehouses' => InventoryLocation::where('tenant_id', $tenantId)->where('location_type', 'warehouse')->where('status', 'active')->count(), 'total_stock_units' => $stock?->units ?? '0', 'total_stock_value' => $stock?->value ?? '0', 'low_stock_items' => $lowStock, 'out_of_stock_items' => $outOfStock, 'pending_transfers' => StockTransfer::where('tenant_id', $tenantId)->whereIn('status', ['draft', 'pending', 'approved', 'dispatched'])->count(), 'pending_goods_receipts' => PurchaseOrder::where('purchase_orders.tenant_id', $tenantId)->whereIn('purchase_orders.status', ['submitted', 'approved', 'partially_received'])->whereIn('destination_location_id', $warehouseIds)->count()]]);
    }

    public function inventory(Request $request, InventoryLocation $warehouse, TenantContext $context): JsonResponse
    {
        $this->assertWarehouse($warehouse, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $balances = $warehouse->balances()->with('variant.product')->where('tenant_id', $context->tenant()->id)->paginate(min((int) $request->input('per_page', 50), 100));
        $translations = app(TranslationService::class);
        $locale = $translations->locale($request);
        $balances->getCollection()->transform(fn (InventoryBalance $balance): array => ['variant_id' => $balance->variant->uuid, 'sku' => $balance->variant->sku, 'product' => $translations->localizedValue($balance->variant->product, 'name', $locale), 'on_hand_quantity' => $balance->on_hand_quantity, 'reserved_quantity' => $balance->reserved_quantity, 'available_quantity' => $balance->available_quantity, 'average_cost' => $balance->average_cost]);

        return response()->json(['success' => true, 'data' => $balances]);
    }

    public function movements(Request $request, InventoryLocation $warehouse, TenantContext $context): JsonResponse
    {
        $this->assertWarehouse($warehouse, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $movements = StockMovement::with('variant.product')->where('tenant_id', $context->tenant()->id)->where('inventory_location_id', $warehouse->id)->latest('created_at')->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => $movements]);
    }

    public function store(CreateWarehouseRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $data += ['tenant_id' => $context->tenant()->id, 'location_type' => 'warehouse', 'status' => 'active'];
        $this->validateManager($data['manager_user_id'] ?? null, $context->tenant()->id);
        if (InventoryLocation::where('tenant_id', $data['tenant_id'])->where('code', $data['code'])->exists()) {
            throw new ConflictHttpException('The inventory location code is already in use.');
        } $location = InventoryLocation::create($data);
        $auditLogger->record('inventory.warehouse_created', $request->user(), $context->tenant(), $location);

        return response()->json(['success' => true, 'data' => new InventoryLocationResource($location)], 201);
    }

    public function show(InventoryLocation $warehouse, TenantContext $context): JsonResponse
    {
        $this->assertWarehouse($warehouse, $context);

        return response()->json(['success' => true, 'data' => new InventoryLocationResource($warehouse->load('store'))]);
    }

    public function update(UpdateWarehouseRequest $request, InventoryLocation $warehouse, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertWarehouse($warehouse, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $this->validateManager($data['manager_user_id'] ?? null, $context->tenant()->id);
        $warehouse->update($data);
        $auditLogger->record('inventory.warehouse_updated', $request->user(), $context->tenant(), $warehouse);

        return response()->json(['success' => true, 'data' => new InventoryLocationResource($warehouse->refresh())]);
    }

    private function assertWarehouse(InventoryLocation $warehouse, TenantContext $context): void
    {
        abort_unless((int) $warehouse->tenant_id === (int) $context->tenant()->id && $warehouse->location_type === 'warehouse', 404);
    }

    private function requireManager($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }

    private function validateManager(?int $userId, int $tenantId): void
    {
        if ($userId !== null && ! \App\Models\TenantMembership::where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'active')->exists()) {
            abort(422, 'The warehouse manager must be an active member of this business.');
        }
    }
}
