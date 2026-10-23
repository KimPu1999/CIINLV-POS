<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductResource;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
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
        $products = $this->productsQuery($request)->orderBy('products.id')->cursor();

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
        $sortColumn = ['name' => 'products.name', 'brand' => 'products.brand', 'status' => 'products.status', 'created_at' => 'products.created_at'][$sort] ?? 'products.name';

        return Product::query()->with([
            'tenant:id,uuid,name,business_code', 'category', 'images',
            'variants.balances.location.store',
        ])->when($request->filled('business'), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $this->matchIdentifier($tenant, 'tenants', $request->string('business')->toString())))
            ->when($request->filled('branch'), fn (Builder $query) => $query->whereHas('variants.balances.location.store', fn (Builder $store) => $this->matchIdentifier($store, 'stores', $request->string('branch')->toString())))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('products.status', $request->string('status')->toString()))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(fn (Builder $nested) => $nested->where('products.name', 'ilike', $like)->orWhere('products.brand', 'ilike', $like)->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'ilike', $like)->orWhere('barcode', $search)));
            })->orderBy($sortColumn, $direction);
    }

    private function productPayload(Product $product, bool $detail = false): array
    {
        $canonical = (new ProductResource($product))->resolve(request());
        $variants = $product->variants->map(fn ($variant): array => [
            'id' => $variant->uuid, 'sku' => $variant->sku, 'barcode' => $variant->barcode,
            'size' => is_array($variant->attributes) ? ($variant->attributes['size'] ?? null) : null,
            'attributes' => $variant->attributes, 'selling_price' => $variant->selling_price,
            'purchase_cost' => $variant->purchase_cost, 'reorder_level' => $variant->reorder_level,
            'status' => $variant->status, 'available_stock' => $variant->balances->sum(fn ($balance): float => (float) $balance->on_hand_quantity - (float) $balance->reserved_quantity),
            'inventory' => $variant->balances->map(fn ($balance): array => [
                'id' => $balance->id, 'on_hand' => $balance->on_hand_quantity, 'reserved' => $balance->reserved_quantity,
                'available' => $balance->available_quantity, 'average_cost' => $balance->average_cost,
                'location' => $balance->location ? ['id' => $balance->location->uuid, 'name' => $balance->location->name, 'code' => $balance->location->code, 'branch' => $balance->location->store ? ['id' => $balance->location->store->uuid, 'name' => $balance->location->store->name, 'code' => $balance->location->store->code] : null] : null,
            ])->values()->all(),
        ])->values()->all();
        $branches = collect($variants)->flatMap(fn (array $variant) => collect($variant['inventory'])->map(fn (array $balance) => $balance['location']['branch'] ?? null))->filter()->unique('id')->values()->all();

        return array_merge($canonical, [
            'business' => $product->tenant ? ['id' => $product->tenant->uuid, 'name' => $product->tenant->name, 'code' => $product->tenant->business_code] : null,
            'branches' => $branches,
            'variants' => $variants,
            'available_stock' => collect($variants)->sum('available_stock'),
        ], $detail ? ['availability' => $product->status === 'active' ? 'available' : 'unavailable'] : []);
    }

    private function matchIdentifier(Builder $query, string $table, string $value): void
    {
        ctype_digit($value) ? $query->where($table.'.id', (int) $value) : $query->where($table.'.uuid', $value);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    private function filterMetadata(Request $request): array
    {
        return $request->only(['search', 'business', 'branch', 'status', 'sort', 'direction']);
    }
}
