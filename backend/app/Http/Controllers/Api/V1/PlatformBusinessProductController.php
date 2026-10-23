<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformBusinessProductController
{
    public function index(Request $request): JsonResponse
    {
        $products = $this->productsQuery($request)->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => $products->getCollection()->map(fn (Product $product): array => $this->productPayload($product))->values(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function branches(Request $request): JsonResponse
    {
        $branches = Store::query()
            ->select(['stores.id', 'stores.uuid', 'stores.tenant_id', 'stores.name', 'stores.code', 'stores.status'])
            ->with('tenant:id,uuid')
            ->when($request->filled('business'), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $this->matchIdentifier($tenant, 'tenants', $request->string('business')->toString())))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(fn (Builder $nested) => $nested->where('stores.name', 'ilike', $search)->orWhere('stores.code', 'ilike', $search));
            })
            ->orderBy('stores.name')
            ->orderBy('stores.id')
            ->paginate($this->perPage($request));

        return response()->json([
            'success' => true,
            'data' => $branches->getCollection()->map(fn (Store $branch): array => [
                'id' => $branch->uuid,
                'name' => $branch->name,
                'code' => $branch->code,
                'status' => $branch->status,
                'business_id' => $branch->tenant?->uuid,
            ])->values(),
            'meta' => [
                'current_page' => $branches->currentPage(),
                'last_page' => $branches->lastPage(),
                'per_page' => $branches->perPage(),
                'total' => $branches->total(),
            ],
        ]);
    }

    public function show(string $product, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $model = $this->productsQuery($request)->where('products.uuid', $product)->firstOrFail();
        $auditLogger->record('platform.business_product_viewed', $request->user(), $model->tenant, $model);

        $history = AuditLog::query()->with('actor:id,name,email')
            ->where('tenant_id', $model->tenant_id)
            ->where(function (Builder $query) use ($model): void {
                $query->where(function (Builder $productQuery) use ($model): void {
                    $productQuery->where('subject_type', Product::class)->where('subject_id', (string) $model->id);
                })->orWhere(function (Builder $variantQuery) use ($model): void {
                    $variantQuery->where('subject_type', ProductVariant::class)
                        ->whereIn('subject_id', $model->variants->pluck('id')->map(fn ($id): string => (string) $id));
                });
            })->latest('created_at')->limit(100)->get()->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->only(['name', 'email']),
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['success' => true, 'data' => array_merge($this->productPayload($model, true), [
            'audit_history' => $history,
        ])]);
    }

    public function export(Request $request, AuditLogger $auditLogger): StreamedResponse
    {
        $auditLogger->record('platform.business_products_exported', $request->user(), metadata: ['filters' => $this->filterMetadata($request)]);
        $products = $this->productsQuery($request)->reorder('products.id')->lazyById(100, 'products.id', 'id');

        return response()->streamDownload(function () use ($products): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Business', 'Branch', 'Product', 'SKU', 'Category', 'Brand', 'Price', 'Variants', 'Stock', 'Status']);
            foreach ($products as $product) {
                $payload = $this->productPayload($product);
                fputcsv($handle, [
                    $payload['business']['name'] ?? '',
                    collect($payload['branches'])->pluck('name')->implode('; '),
                    $payload['name'],
                    $payload['sku'] ?? '',
                    $payload['category']['name'] ?? '',
                    $payload['brand'] ?? '',
                    $payload['selling_price'] ?? '',
                    count($payload['variants']),
                    $payload['available_stock'],
                    $payload['status'],
                ]);
            }
            fclose($handle);
        }, 'business-products-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function productsQuery(Request $request): Builder
    {
        $search = trim($request->string('search')->toString());
        $sort = $request->string('sort', 'name')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $branch = $request->string('branch')->toString();
        $minPrice = $this->numericFilter($request->input('min_price'));
        $maxPrice = $this->numericFilter($request->input('max_price'));
        $createdFrom = $this->dateFilter($request->input('created_from'));
        $createdTo = $this->dateFilter($request->input('created_to'));
        $stockExpression = $this->stockExpression($branch);

        $query = Product::query()->with([
            'tenant:id,uuid,name,business_code', 'category', 'images',
            'variants.balances.location.store',
        ])->when($branch !== '', fn (Builder $query) => $query->with(['variants.balances' => fn ($balance) => $balance->whereHas('location.store', fn (Builder $store) => $this->matchIdentifier($store, 'stores', $branch))]))
            ->when($request->filled('business'), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $this->matchIdentifier($tenant, 'tenants', $request->string('business')->toString())))
            ->when($request->filled('branch'), fn (Builder $query) => $query->whereHas('variants.balances.location.store', fn (Builder $store) => $this->matchIdentifier($store, 'stores', $request->string('branch')->toString())))
            ->when($request->filled('category'), function (Builder $query) use ($request): void {
                $category = $request->string('category')->toString();
                $like = '%'.$category.'%';
                $query->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('categories.name', 'ilike', $like)->orWhere('categories.slug', 'ilike', $like)->orWhere('categories.uuid', $category));
            })
            ->when($request->filled('brand'), fn (Builder $query) => $query->where('products.brand', 'ilike', '%'.$request->string('brand')->toString().'%'))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('products.status', $request->string('status')->toString()))
            ->when($minPrice !== null, fn (Builder $query) => $query->whereHas('variants', fn (Builder $variant) => $variant->where('selling_price', '>=', $minPrice)))
            ->when($maxPrice !== null, fn (Builder $query) => $query->whereHas('variants', fn (Builder $variant) => $variant->where('selling_price', '<=', $maxPrice)))
            ->when($createdFrom !== null, fn (Builder $query) => $query->whereDate('products.created_at', '>=', $createdFrom))
            ->when($createdTo !== null, fn (Builder $query) => $query->whereDate('products.created_at', '<=', $createdTo))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(fn (Builder $nested) => $nested->where('products.name', 'ilike', $like)->orWhere('products.brand', 'ilike', $like)->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'ilike', $like)->orWhere('barcode', 'ilike', $like)));
            })
            ->when($request->input('stock') === 'in_stock', fn (Builder $query) => $query->whereRaw($stockExpression.' > 0', $this->stockBindings($branch))->whereRaw($this->balanceExistsExpression($branch), $this->stockBindings($branch)))
            ->when($request->input('stock') === 'out_of_stock', fn (Builder $query) => $query->whereRaw($stockExpression.' <= 0', $this->stockBindings($branch))->whereRaw($this->balanceExistsExpression($branch), $this->stockBindings($branch)))
            ->when($request->input('stock') === 'low_stock', fn (Builder $query) => $query->whereRaw($stockExpression.' > 0', $this->stockBindings($branch))->whereRaw($stockExpression.' <= (select coalesce(sum(pv2.reorder_level), 0) from product_variants pv2 where pv2.product_id = products.id and pv2.tenant_id = products.tenant_id)', $this->stockBindings($branch))->whereRaw($this->balanceExistsExpression($branch), $this->stockBindings($branch)));

        if ($sort === 'selling_price') {
            $query->orderBy(ProductVariant::query()->select('selling_price')->whereColumn('product_variants.product_id', 'products.id')->orderByDesc('is_default')->orderBy('id')->limit(1), $direction);
        } elseif ($sort === 'available_stock') {
            $query->orderByRaw($stockExpression.' '.$direction, $this->stockBindings($branch));
        } else {
            $sortColumn = ['name' => 'products.name', 'brand' => 'products.brand', 'status' => 'products.status', 'created_at' => 'products.created_at'][$sort] ?? 'products.name';
            $query->orderBy($sortColumn, $direction);
        }

        return $query->orderBy('products.id');
    }

    private function productPayload(Product $product, bool $detail = false): array
    {
        $canonical = (new ProductResource($product))->resolve(request());
        $balances = $product->variants->flatMap(fn ($variant) => $variant->balances);
        $hasInventoryData = $balances->isNotEmpty();
        $onHand = $balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity);
        $reserved = $balances->sum(fn ($balance): float => (float) $balance->reserved_quantity);
        $available = $onHand - $reserved;
        $reorderLevel = $product->variants->sum(fn ($variant): float => (float) $variant->reorder_level);
        $variants = $product->variants->map(fn ($variant): array => [
            'id' => $variant->uuid, 'sku' => $variant->sku, 'barcode' => $variant->barcode,
            'size' => is_array($variant->attributes) ? ($variant->attributes['size'] ?? null) : null,
            'attributes' => $variant->attributes, 'selling_price' => $variant->selling_price,
            'purchase_cost' => $variant->purchase_cost, 'reorder_level' => $variant->reorder_level,
            'status' => $variant->status, 'available_stock' => $variant->balances->isNotEmpty() ? $this->decimal($variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity)) : null,
            'inventory' => $variant->balances->map(fn ($balance): array => [
                'id' => $balance->id, 'on_hand' => $balance->on_hand_quantity, 'reserved' => $balance->reserved_quantity,
                'available' => $balance->available_quantity, 'average_cost' => $balance->average_cost,
                'location' => $balance->location ? ['id' => $balance->location->uuid, 'name' => $balance->location->name, 'code' => $balance->location->code, 'branch' => $balance->location->store ? ['id' => $balance->location->store->uuid, 'name' => $balance->location->store->name, 'code' => $balance->location->store->code] : null] : null,
            ])->values()->all(),
        ])->values()->all();
        $branches = collect($variants)->flatMap(fn (array $variant) => collect($variant['inventory'])->map(fn (array $balance) => $balance['location']['branch'] ?? null))->filter()->unique('id')->values()->all();
        $branchInventory = $balances->filter(fn ($balance) => $balance->location?->store)->groupBy(fn ($balance) => $balance->location->store->id)->map(function ($branchBalances): array {
            $branch = $branchBalances->first()->location->store;
            $onHand = $branchBalances->sum(fn ($balance): float => (float) $balance->on_hand_quantity);
            $reserved = $branchBalances->sum(fn ($balance): float => (float) $balance->reserved_quantity);

            return [
                'branch' => ['id' => $branch->uuid, 'name' => $branch->name, 'code' => $branch->code],
                'on_hand' => $this->decimal($onHand),
                'reserved' => $this->decimal($reserved),
                'available' => $this->decimal($onHand - $reserved),
            ];
        })->values()->all();

        return array_merge($canonical, [
            'business' => $product->tenant ? ['id' => $product->tenant->uuid, 'name' => $product->tenant->name, 'code' => $product->tenant->business_code] : null,
            'barcode' => $product->variants->firstWhere('is_default', true)?->barcode ?: $product->variants->first()?->barcode,
            'branches' => $branches,
            'variants' => $variants,
            'available_stock' => $hasInventoryData ? $this->decimal($available) : null,
            'stock_summary' => [
                'data_available' => $hasInventoryData,
                'on_hand' => $hasInventoryData ? $this->decimal($onHand) : null,
                'reserved' => $hasInventoryData ? $this->decimal($reserved) : null,
                'available' => $hasInventoryData ? $this->decimal($available) : null,
                'reorder_level' => $this->decimal($reorderLevel),
                'low_stock' => $hasInventoryData && $available > 0 && $reorderLevel > 0 && $available <= $reorderLevel,
            ],
            'branch_inventory' => $branchInventory,
        ], $detail ? ['availability' => $product->status === 'active' ? 'available' : 'unavailable'] : []);
    }

    private function stockExpression(string $branch): string
    {
        return '(select coalesce(sum(ib.on_hand_quantity - ib.reserved_quantity), 0) from inventory_balances ib join product_variants pv2 on pv2.id = ib.product_variant_id join inventory_locations il on il.id = ib.inventory_location_id where pv2.product_id = products.id and pv2.tenant_id = products.tenant_id'.$this->branchSql($branch).')';
    }

    private function balanceExistsExpression(string $branch): string
    {
        return 'exists (select 1 from inventory_balances ib join product_variants pv2 on pv2.id = ib.product_variant_id join inventory_locations il on il.id = ib.inventory_location_id where pv2.product_id = products.id and pv2.tenant_id = products.tenant_id'.$this->branchSql($branch).')';
    }

    private function branchSql(string $branch): string
    {
        if ($branch === '') {
            return '';
        }

        return ctype_digit($branch) ? ' and il.store_id = ?' : ' and il.store_id = (select id from stores where uuid = ? limit 1)';
    }

    private function stockBindings(string $branch): array
    {
        return $branch === '' ? [] : [$branch];
    }

    private function matchIdentifier(Builder $query, string $table, string $value): void
    {
        ctype_digit($value) ? $query->where($table.'.id', (int) $value) : $query->where($table.'.uuid', $value);
    }

    private function perPage(Request $request): int
    {
        $perPage = $request->integer('per_page', 25);

        return in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
    }

    private function filterMetadata(Request $request): array
    {
        return $request->only(['search', 'business', 'branch', 'category', 'brand', 'status', 'stock', 'min_price', 'max_price', 'created_from', 'created_to', 'sort', 'direction']);
    }

    private function decimal(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    private function numericFilter(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private function dateFilter(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
