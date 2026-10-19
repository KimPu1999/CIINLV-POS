<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreateSupplierRequest;
use App\Http\Requests\Inventory\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use App\Models\PurchaseOrder;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class SupplierController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $suppliers = Supplier::query()->where('suppliers.tenant_id', $context->tenant()->id)->withCount('purchaseOrders')->withMax('purchaseOrders as last_purchase_at', 'ordered_at')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('suppliers.name', 'ilike', '%'.$request->string('search').'%')->orWhere('suppliers.supplier_code', 'ilike', '%'.$request->string('search').'%')->orWhere('suppliers.contact_person', 'ilike', '%'.$request->string('search').'%')->orWhere('suppliers.email', 'ilike', '%'.$request->string('search').'%')->orWhere('suppliers.phone', 'ilike', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('suppliers.status', $request->string('status')))
            ->when($request->filled('supplier_type'), fn ($q) => $q->where('suppliers.supplier_type', $request->string('supplier_type')))
            ->orderBy('suppliers.name')->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json(['success' => true, 'data' => SupplierResource::collection($suppliers), 'meta' => ['total' => $suppliers->total(), 'per_page' => $suppliers->perPage(), 'current_page' => $suppliers->currentPage(), 'last_page' => $suppliers->lastPage()]]);
    }

    public function statistics(TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $purchaseMonth = PurchaseOrder::query()->where('purchase_orders.tenant_id', $tenantId)->whereBetween('purchase_orders.ordered_at', [$monthStart, $monthEnd])->whereNotIn('purchase_orders.status', ['cancelled', 'draft'])->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')->sum(DB::raw('purchase_order_items.ordered_quantity * purchase_order_items.unit_cost'));

        return response()->json(['success' => true, 'data' => ['total_suppliers' => Supplier::where('tenant_id', $tenantId)->count(), 'active_suppliers' => Supplier::where('tenant_id', $tenantId)->where('status', 'active')->count(), 'inactive_suppliers' => Supplier::where('tenant_id', $tenantId)->whereIn('status', ['inactive', 'archived'])->count(), 'total_purchase_orders' => PurchaseOrder::where('tenant_id', $tenantId)->count(), 'open_purchase_orders' => PurchaseOrder::where('tenant_id', $tenantId)->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received'])->count(), 'purchases_this_month' => number_format((float) $purchaseMonth, 2, '.', ''), 'outstanding_payables' => null, 'payables_available' => false]]);
    }

    public function store(CreateSupplierRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $data['tenant_id'] = $context->tenant()->id;
        $data['supplier_code'] = strtoupper($data['supplier_code']);
        if (Supplier::where('tenant_id', $data['tenant_id'])->where('supplier_code', $data['supplier_code'])->exists()) {
            throw new ConflictHttpException('The supplier code is already in use.');
        } $supplier = Supplier::create($data);
        $auditLogger->record('inventory.supplier_created', $request->user(), $context->tenant(), $supplier);

        return response()->json(['success' => true, 'data' => new SupplierResource($supplier)], 201);
    }

    public function show(Supplier $supplier, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertTenant($supplier, $context);
        $this->requireManager($request->user(), $context->tenant()->id);

        return response()->json(['success' => true, 'data' => new SupplierResource($supplier)]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($supplier, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        if (isset($data['supplier_code'])) {
            $data['supplier_code'] = strtoupper($data['supplier_code']);
            if (Supplier::where('tenant_id', $supplier->tenant_id)->where('supplier_code', $data['supplier_code'])->where('id', '!=', $supplier->id)->exists()) {
                throw new ConflictHttpException('The supplier code is already in use.');
            }
        } $supplier->update($data);
        $auditLogger->record('inventory.supplier_updated', $request->user(), $context->tenant(), $supplier);

        return response()->json(['success' => true, 'data' => new SupplierResource($supplier->refresh())]);
    }

    public function destroy(Supplier $supplier, TenantContext $context, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($supplier, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $supplier->update(['status' => 'archived']);
        $auditLogger->record('inventory.supplier_archived', $request->user(), $context->tenant(), $supplier);

        return response()->json(['success' => true, 'message' => 'Supplier archived.']);
    }

    private function assertTenant(Supplier $supplier, TenantContext $context): void
    {
        abort_unless((int) $supplier->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function requireManager($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }
}
