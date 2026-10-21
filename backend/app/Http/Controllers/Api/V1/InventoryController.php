<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\OpeningStockRequest;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StoreMembership;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\TenantContext;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InventoryController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $balances = InventoryBalance::with(['location.store', 'variant.product'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $this->accessibleLocationIds($request->user(), $context->tenant()->id))->when($request->filled('location_id'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('uuid', (string) $request->string('location_id'))))->when($request->filled('variant_id'), fn ($q) => $q->whereHas('variant', fn ($v) => $v->where('uuid', (string) $request->string('variant_id'))))->paginate(min((int) $request->input('per_page', 50), 100));
        $balances->getCollection()->transform(fn (InventoryBalance $balance) => $this->balanceData($balance));

        return response()->json(['success' => true, 'data' => $balances]);
    }

    public function statistics(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $locationIds = $this->accessibleLocationIds($request->user(), $tenantId);
        $balances = InventoryBalance::query()->where('inventory_balances.tenant_id', $tenantId)->whereIn('inventory_balances.inventory_location_id', $locationIds);
        $totals = (clone $balances)->selectRaw('COALESCE(SUM(on_hand_quantity), 0) AS on_hand, COALESCE(SUM(reserved_quantity), 0) AS reserved, COALESCE(SUM(on_hand_quantity * average_cost), 0) AS value')->first();
        $lowStock = (clone $balances)->join('product_variants', function ($join): void {
            $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
        })->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')->count();
        $outOfStock = (clone $balances)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= 0')->count();

        return response()->json(['success' => true, 'data' => ['total_stock_units' => $totals?->on_hand ?? '0', 'available_stock' => bcsub((string) ($totals?->on_hand ?? 0), (string) ($totals?->reserved ?? 0), 3), 'reserved_stock' => $totals?->reserved ?? '0', 'total_inventory_value' => $totals?->value ?? '0', 'low_stock_products' => $lowStock, 'out_of_stock_products' => $outOfStock, 'locations' => $locationIds->count(), 'recent_movements' => StockMovement::with(['location', 'variant.product'])->where('tenant_id', $tenantId)->whereIn('inventory_location_id', $locationIds)->latest('created_at')->limit(8)->get()->map(fn (StockMovement $movement): array => ['id' => $movement->uuid, 'movement_type' => $movement->movement_type, 'quantity_delta' => $movement->quantity_delta, 'sku' => $movement->variant?->sku, 'product' => $movement->variant?->product?->name, 'location' => $movement->location?->name, 'created_at' => $movement->created_at])]]);
    }

    public function location(InventoryLocation $location, TenantContext $context): JsonResponse
    {
        $this->assertTenant($location, $context);
        Gate::authorize('view', $location);
        $balances = $location->balances()->with('variant.product')->paginate(50);
        $balances->getCollection()->transform(fn (InventoryBalance $balance) => $this->balanceData($balance));

        return response()->json(['success' => true, 'data' => $balances]);
    }

    public function movements(Request $request, TenantContext $context): JsonResponse
    {
        $query = StockMovement::with(['location', 'variant.product'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $this->accessibleLocationIds($request->user(), $context->tenant()->id))->latest('created_at');
        if ($request->filled('location_id')) {
            $location = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $request->string('location_id'))->firstOrFail();
            Gate::authorize('view', $location);
            $query->where('inventory_location_id', $location->id);
        }
        $movements = $query->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => $movements]);
    }

    public function lowStock(TenantContext $context): JsonResponse
    {
        return $this->stockList($context, false);
    }

    public function outOfStock(TenantContext $context): JsonResponse
    {
        return $this->stockList($context, true);
    }

    public function opening(OpeningStockRequest $request, TenantContext $context, InventoryService $service, AuditLogger $auditLogger): JsonResponse
    {
        $location = $this->locationByUuid($request->string('location_id'), $context);
        $variant = $this->variantByUuid($request->string('variant_id'), $context);
        $this->assertWrite($location);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required for stock mutations.');
        }
        $movement = $service->mutate($location, $variant, 'opening_stock', (string) $request->input('quantity'), $request->user(), 'opening_stock', $key, $request->input('reason'), $key, $request->input('unit_cost'));
        $auditLogger->record('inventory.opening_stock', $request->user(), $context->tenant(), $movement, ['location_id' => $location->uuid, 'variant_id' => $variant->uuid]);

        return response()->json(['success' => true, 'data' => $movement], 201);
    }

    public function adjustment(StockAdjustmentRequest $request, TenantContext $context, InventoryService $service, AuditLogger $auditLogger): JsonResponse
    {
        $location = $this->locationByUuid($request->string('location_id'), $context);
        $variant = $this->variantByUuid($request->string('variant_id'), $context);
        $this->assertWrite($location);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required for stock mutations.');
        }
        if (StockAdjustment::where('tenant_id', $context->tenant()->id)->where('idempotency_key', $key)->exists()) {
            return response()->json(['success' => true, 'data' => StockAdjustment::where('tenant_id', $context->tenant()->id)->where('idempotency_key', $key)->first()]);
        }
        $adjustment = DB::transaction(function () use ($request, $context, $service, $location, $variant, $key): StockAdjustment {
            $adjustment = StockAdjustment::create(['tenant_id' => $context->tenant()->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'quantity_delta' => $request->input('quantity_delta'), 'reason' => $request->input('reason'), 'status' => 'approved', 'created_by' => $request->user()->id, 'idempotency_key' => $key]);
            $type = ((float) $request->input('quantity_delta')) > 0 ? 'adjustment_in' : 'adjustment_out';
            $service->mutate($location, $variant, $type, (string) $request->input('quantity_delta'), $request->user(), 'stock_adjustment', $adjustment->uuid, $request->input('reason'), $key);

            return $adjustment;
        });
        $auditLogger->record('inventory.stock_adjusted', $request->user(), $context->tenant(), $adjustment, ['location_id' => $location->uuid, 'variant_id' => $variant->uuid, 'quantity_delta' => $adjustment->quantity_delta]);

        return response()->json(['success' => true, 'data' => $adjustment], 201);
    }

    public function adjustments(TenantContext $context): JsonResponse
    {
        $rows = StockAdjustment::with(['location', 'variant.product'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $this->accessibleLocationIds(request()->user(), $context->tenant()->id))->latest()->paginate(50);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    private function stockList(TenantContext $context, bool $out): JsonResponse
    {
        $query = InventoryBalance::with(['location', 'variant.product'])
            ->join('product_variants', function ($join): void {
                $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')
                    ->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
            })
            ->where('inventory_balances.tenant_id', $context->tenant()->id)
            ->whereIn('inventory_balances.inventory_location_id', $this->accessibleLocationIds(request()->user(), $context->tenant()->id))
            ->select('inventory_balances.*')
            ->when($out, fn ($q) => $q->whereRaw('inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity <= 0'), fn ($q) => $q->whereRaw('inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity <= product_variants.reorder_level'));

        return response()->json(['success' => true, 'data' => $query->paginate(50)]);
    }

    private function balanceData(InventoryBalance $balance): array
    {
        $translations = app(TranslationService::class);
        $locale = $translations->locale(request());

        return ['location' => ['id' => $balance->location->uuid, 'name' => $translations->localizedValue($balance->location, 'name', $locale)], 'variant' => ['id' => $balance->variant->uuid, 'sku' => $balance->variant->sku, 'product' => $translations->localizedValue($balance->variant->product, 'name', $locale)], 'on_hand_quantity' => $balance->on_hand_quantity, 'reserved_quantity' => $balance->reserved_quantity, 'available_quantity' => $balance->available_quantity, 'average_cost' => $balance->average_cost, 'version' => $balance->version];
    }

    private function locationByUuid(string $uuid, TenantContext $context): InventoryLocation
    {
        $location = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $uuid)->firstOrFail();

        return $location;
    }

    private function variantByUuid(string $uuid, TenantContext $context): ProductVariant
    {
        return ProductVariant::where('tenant_id', $context->tenant()->id)->where('uuid', $uuid)->where('status', 'active')->firstOrFail();
    }

    private function assertTenant(InventoryLocation $location, TenantContext $context): void
    {
        abort_unless((int) $location->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function assertWrite(InventoryLocation $location): void
    {
        Gate::authorize('write', $location);
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
