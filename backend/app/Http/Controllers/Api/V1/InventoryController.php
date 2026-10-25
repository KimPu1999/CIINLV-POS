<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\OpeningStockRequest;
use App\Http\Requests\Inventory\BulkInventoryThresholdRequest;
use App\Http\Requests\Inventory\SetInventoryQuantityRequest;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StoreMembership;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use App\Services\TenantContext;
use App\Services\TranslationService;
use App\Support\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class InventoryController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $request->validate(['product_id' => ['nullable', 'uuid'], 'variant_id' => ['nullable', 'uuid'], 'location_id' => ['nullable', 'uuid'], 'store_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:120'], 'brand' => ['nullable', 'string', 'max:120'], 'category_id' => ['nullable', 'uuid'], 'product_status' => ['nullable', 'in:active,inactive,archived'], 'stock_status' => ['nullable', 'in:in_stock,low_stock,out_of_stock,archived'], 'sort' => ['nullable', 'in:updated_at,product_name,sku,available_quantity'], 'direction' => ['nullable', 'in:asc,desc'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:250']]);
        $locationIds = $this->accessibleLocationIds($request->user(), $context->tenant()->id);
        if ($request->filled('store_id')) {
            $locationIds = $locationIds->intersect(InventoryLocation::where('tenant_id', $context->tenant()->id)->whereHas('store', fn ($store) => $store->where('uuid', $request->string('store_id')))->pluck('id'));
        }
        if ($request->filled('location_id')) {
            $locationIds = $locationIds->intersect(InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $request->string('location_id'))->pluck('id'));
        }
        $balances = InventoryBalance::query()->select('inventory_balances.*')->join('product_variants', function ($join): void {
            $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
        })->join('products', function ($join): void {
            $join->on('products.id', '=', 'product_variants.product_id')->on('products.tenant_id', '=', 'product_variants.tenant_id');
        })->with(['location.store', 'variant.product.category', 'variant.product.images'])->where('inventory_balances.tenant_id', $context->tenant()->id)->whereIn('inventory_balances.inventory_location_id', $locationIds)->when($request->filled('product_id'), fn ($q) => $q->whereHas('variant.product', fn ($p) => $p->where('products.uuid', (string) $request->string('product_id'))->where('products.tenant_id', $context->tenant()->id)))->when($request->filled('variant_id'), fn ($q) => $q->whereHas('variant', fn ($v) => $v->where('uuid', (string) $request->string('variant_id'))))->when($request->filled('search'), fn ($q) => $q->where(function ($searchQuery) use ($request): void {
            $term = '%'.$request->string('search').'%';
            $searchQuery->whereHas('variant.product', fn ($p) => $p->where('products.name', 'ilike', $term)->orWhere('products.brand', 'ilike', $term))->orWhereHas('variant', fn ($v) => $v->where('sku', 'ilike', $term)->orWhere('barcode', 'ilike', $term));
        }))->when($request->filled('brand'), function ($q) use ($request): void {
            $brand = (string) $request->string('brand');
            $q->whereHas('variant.product', function ($p) use ($brand): void {
                if ($brand === '__unbranded__') {
                    $p->whereNull('products.brand')->orWhereRaw("trim(products.brand) = ''");
                } else {
                    $p->where('products.brand', 'ilike', '%'.$brand.'%');
                }
            });
        })->when($request->filled('category_id'), fn ($q) => $q->whereHas('variant.product.category', fn ($c) => $c->where('categories.uuid', (string) $request->string('category_id'))))->when($request->filled('product_status'), fn ($q) => $q->whereHas('variant.product', fn ($p) => $p->where('products.status', (string) $request->string('product_status'))))->when($request->input('stock_status') === 'archived', fn ($q) => $q->whereHas('variant.product', fn ($p) => $p->where('products.status', 'archived')))->when($request->input('stock_status') === 'out_of_stock', fn ($q) => $q->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= 0'))->when($request->input('stock_status') === 'in_stock', fn ($q) => $q->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0'))->when($request->input('stock_status') === 'low_stock', fn ($q) => $q->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level'));
        $sort = $request->input('sort', 'updated_at');
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        if ($sort === 'available_quantity') {
            $balances->orderByRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) '.$direction);
        } else {
            $balances->orderBy(match ($sort) {
                'product_name' => 'products.name',
                'sku' => 'product_variants.sku',
                default => 'inventory_balances.updated_at',
            }, $direction);
        }
        $balances->orderBy('inventory_balances.id');
        $balances = $balances->paginate(min(max((int) $request->input('per_page', 25), 1), 250));
        $balances->getCollection()->transform(fn (InventoryBalance $balance) => $this->balanceData($balance));

        return response()->json(['success' => true, 'data' => $balances]);
    }

    public function statistics(Request $request, TenantContext $context): JsonResponse
    {
        $request->validate(['store_id' => ['nullable', 'uuid'], 'location_id' => ['nullable', 'uuid']]);
        $tenantId = $context->tenant()->id;
        $locationIds = $this->accessibleLocationIds($request->user(), $tenantId);
        if ($request->filled('store_id')) {
            $locationIds = $locationIds->intersect(InventoryLocation::where('tenant_id', $tenantId)->whereHas('store', fn ($store) => $store->where('uuid', $request->string('store_id')))->pluck('id'));
        }
        if ($request->filled('location_id')) {
            $locationIds = $locationIds->intersect(InventoryLocation::where('tenant_id', $tenantId)->where('uuid', $request->string('location_id'))->pluck('id'));
        }
        $balances = InventoryBalance::query()->where('inventory_balances.tenant_id', $tenantId)->whereIn('inventory_balances.inventory_location_id', $locationIds);
        $totals = (clone $balances)->selectRaw('COALESCE(SUM(on_hand_quantity), 0) AS on_hand, COALESCE(SUM(reserved_quantity), 0) AS reserved, COALESCE(SUM(on_hand_quantity * average_cost), 0) AS value')->first();
        $lowStock = (clone $balances)->join('product_variants', function ($join): void {
            $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
        })->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')->distinct()->count('product_variants.product_id');
        $outOfStock = (clone $balances)->join('product_variants', function ($join): void {
            $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
        })->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= 0')->distinct()->count('product_variants.product_id');
        $inStock = (clone $balances)->join('product_variants', function ($join): void {
            $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
        })->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')->distinct()->count('product_variants.product_id');

        $totalProducts = Product::where('tenant_id', $tenantId)->whereHas('variants.balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds))->count();

        return response()->json(['success' => true, 'data' => ['total_products' => $totalProducts, 'in_stock_products' => $inStock, 'total_stock_units' => $totals?->on_hand ?? '0', 'available_stock' => bcsub((string) ($totals?->on_hand ?? 0), (string) ($totals?->reserved ?? 0), 3), 'reserved_stock' => $totals?->reserved ?? '0', 'total_inventory_value' => $totals?->value ?? '0', 'low_stock_products' => $lowStock, 'out_of_stock_products' => $outOfStock, 'locations' => $locationIds->count(), 'recent_movements' => StockMovement::with(['location', 'variant.product'])->where('tenant_id', $tenantId)->whereIn('inventory_location_id', $locationIds)->latest('created_at')->limit(8)->get()->map(fn (StockMovement $movement): array => ['id' => $movement->uuid, 'movement_type' => $movement->movement_type, 'quantity_delta' => $movement->quantity_delta, 'sku' => $movement->variant?->sku, 'product' => $movement->variant?->product?->name, 'location' => $movement->location?->name, 'created_at' => $movement->created_at])]]);
    }

    public function filters(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $locationIds = $this->accessibleLocationIds($request->user(), $tenantId);
        $brands = Product::query()->where('tenant_id', $tenantId)->whereNotNull('brand')->whereRaw("trim(brand) <> ''")->distinct()->orderBy('brand')->pluck('brand')->values();
        $categories = $context->tenant()->categories()->whereIn('status', ['active', 'inactive'])->orderBy('name')->get(['uuid', 'name'])->map(fn ($category): array => ['id' => $category->uuid, 'name' => $category->name])->values();
        $locations = InventoryLocation::query()->where('tenant_id', $tenantId)->whereIn('id', $locationIds)->where('status', 'active')->with('store')->orderBy('name')->get()->map(fn (InventoryLocation $location): array => ['id' => $location->uuid, 'name' => $location->name, 'code' => $location->code, 'location_type' => $location->location_type, 'branch' => $location->store?->name])->values();

        return response()->json(['success' => true, 'data' => ['brands' => $brands, 'categories' => $categories, 'locations' => $locations]]);
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
        $request->validate(['product_id' => ['nullable', 'uuid'], 'variant_id' => ['nullable', 'uuid'], 'location_id' => ['nullable', 'uuid'], 'store_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:120'], 'movement_type' => ['nullable', 'string', 'max:60'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $locationIds = $this->accessibleLocationIds($request->user(), $context->tenant()->id);
        if ($request->filled('store_id')) {
            $locationIds = $locationIds->intersect(InventoryLocation::where('tenant_id', $context->tenant()->id)->whereHas('store', fn ($store) => $store->where('uuid', $request->string('store_id')))->pluck('id'));
        }
        $query = StockMovement::with(['location.store', 'variant.product', 'actor'])->where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $locationIds)->when($request->filled('movement_type'), fn ($q) => $q->where('movement_type', $request->string('movement_type')))->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')))->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))->when($request->filled('search'), fn ($q) => $q->where(function ($searchQuery) use ($request): void {
            $term = '%'.$request->string('search').'%';
            $searchQuery->where('reason', 'ilike', $term)->orWhereHas('variant', fn ($v) => $v->where('sku', 'ilike', $term)->orWhere('barcode', 'ilike', $term))->orWhereHas('variant.product', fn ($p) => $p->where('name', 'ilike', $term));
        }))->latest('created_at');
        $query->when($request->filled('product_id'), fn ($q) => $q->whereHas('variant.product', fn ($p) => $p->where('products.uuid', (string) $request->string('product_id'))->where('products.tenant_id', $context->tenant()->id)));
        $query->when($request->filled('variant_id'), fn ($q) => $q->whereHas('variant', fn ($v) => $v->where('product_variants.uuid', (string) $request->string('variant_id'))));
        if ($request->filled('location_id')) {
            $location = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $request->string('location_id'))->firstOrFail();
            Gate::authorize('view', $location);
            $query->where('inventory_location_id', $location->id);
        }
        $movements = $query->paginate(min((int) $request->input('per_page', 25), 100));
        $movements->getCollection()->transform(fn (StockMovement $movement): array => ['id' => $movement->uuid, 'movement_type' => $movement->movement_type, 'quantity_delta' => $movement->quantity_delta, 'quantity_before' => $movement->quantity_before, 'quantity_after' => $movement->quantity_after, 'reason' => $movement->reason, 'reference_type' => $movement->reference_type, 'reference_id' => $movement->reference_id, 'created_at' => $movement->created_at, 'actor' => $movement->actor?->only(['id', 'name', 'email']), 'location' => ['id' => $movement->location?->uuid, 'name' => $movement->location?->name, 'code' => $movement->location?->code, 'branch' => $movement->location?->store?->name], 'variant' => ['id' => $movement->variant?->uuid, 'sku' => $movement->variant?->sku, 'product' => $movement->variant?->product?->name]]);

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
        if (strlen($key) > 150) {
            throw new UnprocessableEntityHttpException('Idempotency-Key must not exceed 150 characters.');
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($request->filled('notes')) {
            $reason .= ($reason ? ' — ' : '').trim((string) $request->string('notes'));
        }
        $movement = $service->mutate($location, $variant, 'opening_stock', (string) $request->input('quantity'), $request->user(), 'opening_stock', $this->movementReferenceId($request, $key), $reason ?: null, $key, $request->input('unit_cost'));
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
        if (strlen($key) > 150) {
            throw new UnprocessableEntityHttpException('Idempotency-Key must not exceed 150 characters.');
        }
        if (StockAdjustment::where('tenant_id', $context->tenant()->id)->where('idempotency_key', $key)->exists()) {
            return response()->json(['success' => true, 'data' => StockAdjustment::where('tenant_id', $context->tenant()->id)->where('idempotency_key', $key)->first()]);
        }
        $adjustment = DB::transaction(function () use ($request, $context, $service, $location, $variant, $key): StockAdjustment {
            $reason = (string) $request->input('reason');
            if ($request->filled('notes')) {
                $reason .= ' — '.trim((string) $request->string('notes'));
            }
            $adjustment = StockAdjustment::create(['tenant_id' => $context->tenant()->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'quantity_delta' => $request->input('quantity_delta'), 'reason' => $reason, 'status' => 'approved', 'created_by' => $request->user()->id, 'idempotency_key' => $key]);
            $type = ((float) $request->input('quantity_delta')) > 0 ? 'adjustment_in' : 'adjustment_out';
            $service->mutate($location, $variant, $type, (string) $request->input('quantity_delta'), $request->user(), 'stock_adjustment', $request->input('reference_number') ?: $adjustment->uuid, $reason, $key);

            return $adjustment;
        });
        $auditLogger->record('inventory.stock_adjusted', $request->user(), $context->tenant(), $adjustment, ['location_id' => $location->uuid, 'variant_id' => $variant->uuid, 'quantity_delta' => $adjustment->quantity_delta]);

        return response()->json(['success' => true, 'data' => $adjustment], 201);
    }

    public function quantity(SetInventoryQuantityRequest $request, TenantContext $context, InventoryService $service, AuditLogger $auditLogger): JsonResponse
    {
        $location = $this->locationByUuid($request->string('location_id'), $context);
        $variant = $this->variantByUuid($request->string('variant_id'), $context);
        $this->assertWrite($location);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required for stock counts.');
        }
        if (strlen($key) > 150) {
            throw new UnprocessableEntityHttpException('Idempotency-Key must not exceed 150 characters.');
        }

        $reason = trim((string) $request->string('reason'));
        if ($request->filled('notes')) {
            $reason .= ' — '.trim((string) $request->string('notes'));
        }
        $movement = $service->setQuantity(
            $location,
            $variant,
            (string) $request->input('quantity'),
            $request->user(),
            'stock_count',
            $this->movementReferenceId($request, $key),
            $reason,
            $key,
            $request->input('unit_cost')
        );
        $auditLogger->record('inventory.stock_counted', $request->user(), $context->tenant(), $movement ?: $variant, ['location_id' => $location->uuid, 'variant_id' => $variant->uuid, 'quantity' => $request->input('quantity')]);

        return response()->json(['success' => true, 'data' => $movement], 201);
    }

    public function bulkThreshold(BulkInventoryThresholdRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();
        $variants = ProductVariant::query()->where('tenant_id', $context->tenant()->id)->whereIn('uuid', array_values(array_unique($data['variant_ids'])))->with('product')->get();
        if ($variants->count() !== count(array_unique($data['variant_ids']))) {
            throw ValidationException::withMessages(['variant_ids' => 'One or more variants are not available in this business.']);
        }

        DB::transaction(function () use ($variants, $data, $request, $context, $auditLogger): void {
            foreach ($variants as $variant) {
                Gate::forUser($request->user())->authorize('manage', $variant->product);
                $previous = (string) $variant->reorder_level;
                $variant->update(['reorder_level' => $data['reorder_level']]);
                $auditLogger->record('inventory.reorder_level_bulk_updated', $request->user(), $context->tenant(), $variant, ['previous' => $previous, 'new' => (string) $data['reorder_level'], 'reason' => $data['reason'] ?? null]);
            }
        });

        return response()->json(['success' => true, 'data' => ['updated' => $variants->count(), 'reorder_level' => $data['reorder_level']]]);
    }

    public function import(Request $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], 'preview' => ['nullable', 'boolean'], 'confirm' => ['nullable', 'boolean']]);
        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'rb');
        $header = $handle ? fgetcsv($handle) : false;
        if (! is_array($header)) {
            throw ValidationException::withMessages(['file' => 'The CSV file is empty.']);
        }
        $header = array_map(fn ($value): string => strtolower(trim((string) $value)), $header);
        if (! in_array('variant_id', $header, true) && ! in_array('sku', $header, true)) {
            throw ValidationException::withMessages(['file' => 'CSV must contain variant_id or sku, plus reorder_level.']);
        }
        if (! in_array('reorder_level', $header, true)) {
            throw ValidationException::withMessages(['file' => 'CSV must contain a reorder_level column.']);
        }

        $rows = [];
        $errors = [];
        $seen = [];
        while (($values = fgetcsv($handle)) !== false && count($rows) + count($errors) < 1000) {
            if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $record = [];
            foreach ($header as $index => $key) {
                $record[$key] = trim((string) ($values[$index] ?? ''));
            }
            $lookup = ($record['variant_id'] ?? '') ?: ($record['sku'] ?? '');
            $level = $record['reorder_level'] ?? '';
            if ($lookup === '' || ! is_numeric($level) || (float) $level < 0) {
                $errors[] = ['row' => count($rows) + count($errors) + 2, 'message' => 'A variant identifier and non-negative reorder_level are required.'];
                continue;
            }
            $variantQuery = ProductVariant::query()->where('tenant_id', $context->tenant()->id)->with('product');
            if (Str::isUuid($lookup)) {
                $variantQuery->where('uuid', $lookup);
            } else {
                $variantQuery->where('sku', $lookup);
            }
            $variant = $variantQuery->first();
            if (! $variant) {
                $errors[] = ['row' => count($rows) + count($errors) + 2, 'message' => 'Variant is not available in this business.', 'identifier' => $lookup];
                continue;
            }
            if (isset($seen[$variant->uuid])) {
                $errors[] = ['row' => count($rows) + count($errors) + 2, 'message' => 'The same variant appears more than once.', 'identifier' => $lookup];
                continue;
            }
            $seen[$variant->uuid] = true;
            $rows[] = ['variant_id' => $variant->uuid, 'sku' => $variant->sku, 'product' => $variant->product?->name, 'reorder_level' => (string) $level];
        }
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (count($rows) + count($errors) >= 1000) {
            $errors[] = ['row' => 1001, 'message' => 'CSV import is limited to 1,000 rows per request.'];
        }
        if ($request->boolean('preview', true) || ! $request->boolean('confirm')) {
            return response()->json(['success' => true, 'data' => ['preview' => true, 'valid_count' => count($rows), 'error_count' => count($errors), 'rows' => $rows, 'errors' => $errors]]);
        }
        if ($errors) {
            throw ValidationException::withMessages(['file' => 'Resolve CSV validation errors before importing.']);
        }

        DB::transaction(function () use ($rows, $request, $context, $auditLogger): void {
            foreach ($rows as $row) {
                $variant = ProductVariant::where('tenant_id', $context->tenant()->id)->where('uuid', $row['variant_id'])->with('product')->firstOrFail();
                Gate::forUser($request->user())->authorize('manage', $variant->product);
                $previous = (string) $variant->reorder_level;
                $variant->update(['reorder_level' => $row['reorder_level']]);
                $auditLogger->record('inventory.reorder_level_imported', $request->user(), $context->tenant(), $variant, ['previous' => $previous, 'new' => $row['reorder_level']]);
            }
        });

        return response()->json(['success' => true, 'data' => ['updated' => count($rows), 'errors' => []]]);
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
        $product = $balance->variant?->product;
        $primaryImage = $product?->images?->firstWhere('is_primary', true) ?: $product?->images?->first();

        return ['location' => ['id' => $balance->location->uuid, 'name' => $translations->localizedValue($balance->location, 'name', $locale), 'code' => $balance->location->code], 'branch' => $balance->location->store ? ['id' => $balance->location->store->uuid, 'name' => $balance->location->store->name, 'code' => $balance->location->store->code] : null, 'product' => ['id' => $product?->uuid, 'name' => $product ? $translations->localizedValue($product, 'name', $locale) : null, 'brand' => $product ? $translations->localizedValue($product, 'brand', $locale) : null, 'category' => $product?->category?->name, 'status' => $product?->status, 'image_url' => $primaryImage ? PublicMediaUrl::make($primaryImage->storage_path) : null], 'variant' => ['id' => $balance->variant->uuid, 'sku' => $balance->variant->sku, 'barcode' => $balance->variant->barcode, 'product' => $product ? $translations->localizedValue($product, 'name', $locale) : null, 'reorder_level' => $balance->variant->reorder_level, 'attributes' => $balance->variant->attributes, 'size' => is_array($balance->variant->attributes) ? ($balance->variant->attributes['size'] ?? null) : null], 'on_hand_quantity' => $balance->on_hand_quantity, 'reserved_quantity' => $balance->reserved_quantity, 'available_quantity' => $balance->available_quantity, 'average_cost' => $balance->average_cost, 'version' => $balance->version, 'updated_at' => $balance->updated_at];
    }

    private function locationByUuid(string $uuid, TenantContext $context): InventoryLocation
    {
        $location = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $uuid)->firstOrFail();

        return $location;
    }

    private function movementReferenceId(Request $request, string $idempotencyKey): string
    {
        $reference = trim((string) $request->input('reference_number', ''));

        return $reference !== '' ? $reference : 'idem-'.substr(hash('sha256', $idempotencyKey), 0, 32);
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
        if ($user->isSuperAdmin() && app(TenantContext::class)->allowsPlatformAccessTo($tenantId)) {
            return InventoryLocation::where('tenant_id', $tenantId)->where('status', 'active')->pluck('id');
        }

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
