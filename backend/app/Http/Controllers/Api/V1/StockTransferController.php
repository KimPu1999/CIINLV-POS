<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreateStockTransferRequest;
use App\Http\Requests\Inventory\ReceiveStockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StoreMembership;
use App\Services\AuditLogger;
use App\Services\StockTransferService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class StockTransferController
{
    public function index(TenantContext $context, Request $request): JsonResponse
    {
        $ids = $this->accessibleLocationIds(request()->user(), $context->tenant()->id);
        $transfers = StockTransfer::query()->where('stock_transfers.tenant_id', $context->tenant()->id)->where(function ($query) use ($ids): void {
            $query->whereIn('source_location_id', $ids)->orWhereIn('destination_location_id', $ids);
        })->with(['source', 'destination', 'items.variant.product'])->withCount('items')->when($request->filled('search'), fn ($q) => $q->where(fn ($inner) => $inner->where('stock_transfers.transfer_number', 'ilike', '%'.$request->string('search').'%')->orWhereHas('items.variant', fn ($variant) => $variant->where('product_variants.sku', 'ilike', '%'.$request->string('search').'%'))))->when($request->filled('status'), fn ($q) => $q->where('stock_transfers.status', $request->string('status')))->latest('stock_transfers.created_at')->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json(['success' => true, 'data' => StockTransferResource::collection($transfers), 'meta' => ['total' => $transfers->total(), 'current_page' => $transfers->currentPage(), 'last_page' => $transfers->lastPage()]]);
    }

    public function statistics(TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $ids = $this->accessibleLocationIds(request()->user(), $tenantId);
        $query = StockTransfer::query()->where('tenant_id', $tenantId)->where(function ($q) use ($ids): void { $q->whereIn('source_location_id', $ids)->orWhereIn('destination_location_id', $ids); });

        return response()->json(['success' => true, 'data' => ['total_transfers' => (clone $query)->count(), 'draft_transfers' => (clone $query)->where('status', 'draft')->count(), 'pending_approval' => (clone $query)->where('status', 'pending_approval')->count(), 'approved_transfers' => (clone $query)->where('status', 'approved')->count(), 'in_transit' => (clone $query)->where('status', 'dispatched')->count(), 'partially_received' => (clone $query)->where('status', 'partially_received')->count(), 'completed_transfers' => (clone $query)->where('status', 'received')->count(), 'cancelled_transfers' => (clone $query)->where('status', 'cancelled')->count(), 'unresolved_discrepancies' => DB::table('stock_transfer_items')->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_items.stock_transfer_id')->where('stock_transfers.tenant_id', $tenantId)->where(function ($q) use ($ids): void { $q->whereIn('stock_transfers.source_location_id', $ids)->orWhereIn('stock_transfers.destination_location_id', $ids); })->where(fn ($q) => $q->where('stock_transfer_items.damaged_quantity', '>', 0)->orWhere('stock_transfer_items.missing_quantity', '>', 0))->count()]]);
    }

    public function locations(TenantContext $context): JsonResponse
    {
        $ids = $this->accessibleLocationIds(request()->user(), $context->tenant()->id);

        return response()->json(['success' => true, 'data' => InventoryLocation::where('tenant_id', $context->tenant()->id)->whereIn('id', $ids)->where('status', 'active')->orderBy('name')->get()->map(fn (InventoryLocation $location): array => ['id' => $location->uuid, 'name' => $location->name, 'code' => $location->code, 'location_type' => $location->location_type])]);
    }

    public function store(CreateStockTransferRequest $request, TenantContext $context, StockTransferService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireTransferRole($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $source = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $data['source_location_id'])->firstOrFail();
        $destination = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $data['destination_location_id'])->firstOrFail();
        $service->assertLocationPair($source, $destination, $context->tenant()->id);
        Gate::forUser($request->user())->authorize('write', $source);
        Gate::forUser($request->user())->authorize('write', $destination);
        $transfer = DB::transaction(function () use ($data, $context, $request, $source, $destination): StockTransfer {
            if (StockTransfer::where('tenant_id', $context->tenant()->id)->where('transfer_number', $data['transfer_number'])->exists()) {
                throw new ConflictHttpException('The transfer number is already in use.');
            } $transfer = StockTransfer::create(['tenant_id' => $context->tenant()->id, 'source_location_id' => $source->id, 'destination_location_id' => $destination->id, 'transfer_number' => $data['transfer_number'], 'status' => 'draft', 'created_by' => $request->user()->id]);
            foreach ($data['items'] as $item) {
                $variant = ProductVariant::where('tenant_id', $context->tenant()->id)->where('uuid', $item['variant_id'])->where('status', 'active')->firstOrFail();
                $transfer->items()->create(['product_variant_id' => $variant->id, 'requested_quantity' => $item['requested_quantity']]);
            }

            return $transfer;
        });
        $auditLogger->record('inventory.stock_transfer_created', $request->user(), $context->tenant(), $transfer);

        return response()->json(['success' => true, 'data' => new StockTransferResource($transfer->load(['source', 'destination', 'items.variant']))], 201);
    }

    public function show(StockTransfer $transfer, TenantContext $context): JsonResponse
    {
        $this->assertTenant($transfer, $context);
        Gate::authorize('view', $transfer);
        $this->authorizeLocations($transfer, request()->user(), false);

        return response()->json(['success' => true, 'data' => new StockTransferResource($transfer->load(['source', 'destination', 'items.variant']))]);
    }

    public function submit(StockTransfer $transfer, TenantContext $context, Request $request, StockTransferService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($transfer, $context);
        Gate::authorize('manage', $transfer);
        $this->authorizeLocations($transfer, $request->user(), true);
        $result = $service->submit($transfer);
        $auditLogger->record('inventory.stock_transfer_submitted', $request->user(), $context->tenant(), $result);

        return response()->json(['success' => true, 'data' => new StockTransferResource($result)]);
    }

    public function approve(StockTransfer $transfer, TenantContext $context, Request $request, StockTransferService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($transfer, $context);
        Gate::authorize('manage', $transfer);
        $this->authorizeLocations($transfer, $request->user(), true);
        $result = $service->approve($transfer);
        $auditLogger->record('inventory.stock_transfer_approved', $request->user(), $context->tenant(), $result);

        return response()->json(['success' => true, 'data' => new StockTransferResource($result)]);
    }

    public function dispatch(StockTransfer $transfer, TenantContext $context, Request $request, StockTransferService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($transfer, $context);
        Gate::authorize('manage', $transfer);
        $this->authorizeLocations($transfer, $request->user(), true);
        $result = $service->dispatch($transfer, $request->user());
        $auditLogger->record('inventory.stock_transfer_dispatched', $request->user(), $context->tenant(), $result);

        return response()->json(['success' => true, 'data' => new StockTransferResource($result)]);
    }

    public function receive(ReceiveStockTransferRequest $request, StockTransfer $transfer, TenantContext $context, StockTransferService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($transfer, $context);
        Gate::authorize('manage', $transfer);
        $this->authorizeLocations($transfer, $request->user(), true);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required for transfer receiving.');
        } $result = $service->receive($transfer, $request->validated()['items'], $request->user(), $key);
        $auditLogger->record('inventory.stock_transfer_received', $request->user(), $context->tenant(), $result, ['idempotency_key' => $key]);

        return response()->json(['success' => true, 'data' => new StockTransferResource($result)]);
    }

    public function cancel(StockTransfer $transfer, TenantContext $context, Request $request, StockTransferService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($transfer, $context);
        Gate::authorize('manage', $transfer);
        $this->authorizeLocations($transfer, $request->user(), true);
        $result = $service->cancel($transfer);
        $auditLogger->record('inventory.stock_transfer_cancelled', $request->user(), $context->tenant(), $result);

        return response()->json(['success' => true, 'data' => new StockTransferResource($result)]);
    }

    private function assertTenant(StockTransfer $transfer, TenantContext $context): void
    {
        abort_unless((int) $transfer->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function requireTransferRole($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager', 'store_manager']))->exists(), 403);
    }

    private function authorizeLocations(StockTransfer $transfer, $user, bool $write): void
    {
        $transfer->loadMissing(['source', 'destination']);
        Gate::forUser($user)->authorize($write ? 'write' : 'view', $transfer->source);
        Gate::forUser($user)->authorize($write ? 'write' : 'view', $transfer->destination);
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
