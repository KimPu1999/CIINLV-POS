<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreateWarehouseRequest;
use App\Http\Requests\Inventory\UpdateWarehouseRequest;
use App\Http\Resources\InventoryLocationResource;
use App\Models\InventoryLocation;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WarehouseController
{
    public function index(TenantContext $context): JsonResponse
    {
        return response()->json(['success' => true, 'data' => InventoryLocationResource::collection(InventoryLocation::where('tenant_id', $context->tenant()->id)->where('location_type', 'warehouse')->with('store')->orderBy('name')->get())]);
    }

    public function store(CreateWarehouseRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $data += ['tenant_id' => $context->tenant()->id, 'location_type' => 'warehouse', 'status' => 'active'];
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
        $warehouse->update($request->validated());
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
}
