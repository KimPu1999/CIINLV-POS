<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreateProductRequest;
use App\Http\Requests\Inventory\CreateVariantRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Http\Requests\Inventory\UploadProductImagesRequest;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductVariantResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\ProductService;
use App\Services\ReportScopeService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductController
{
    public function index(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,inactive,archived'],
            'stock_status' => ['nullable', 'in:in_stock,low_stock,out_of_stock'],
            'category_id' => ['nullable', 'string', 'max:36'],
            'brand' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'string', 'max:36'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:name,created_at,status,selling_price,available_stock'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $tenant = $context->tenant();
        $query = Product::query()->where('products.tenant_id', $tenant->id);
        $locations = $scope->locationIds($request->user(), $tenant, $request->filled('branch_id') ? (string) $request->input('branch_id') : null);

        if ($request->filled('branch_id')) {
            // Non-inventory products do not need an inventory balance row. For
            // tracked products, keep the existing store-location boundary so
            // POS never presents stock belonging to another store or tenant.
            $query->where(function ($product) use ($locations): void {
                $product->where('products.track_inventory', false)
                    ->orWhereHas('variants.balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations));
            });
        }

        $query->with([
            'category', 'images', 'variants',
            'variants.balances' => fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations),
        ])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('products.name', 'ilike', '%'.$request->string('search').'%')->orWhere('products.brand', 'ilike', '%'.$request->string('search').'%')->orWhereHas('variants', fn ($v) => $v->where('product_variants.sku', 'ilike', '%'.$request->string('search').'%')->orWhere('product_variants.barcode', 'ilike', '%'.$request->string('search').'%'))))
            ->when($request->filled('category_id'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('categories.uuid', $request->string('category_id'))))
            ->when($request->filled('brand'), fn ($q) => $q->where('products.brand', 'ilike', '%'.$request->string('brand').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('products.status', $request->string('status')))
            ->when($request->filled('min_price'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('product_variants.selling_price', '>=', $request->input('min_price'))))
            ->when($request->filled('max_price'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('product_variants.selling_price', '<=', $request->input('max_price'))))
            ->when($request->filled('created_from'), fn ($q) => $q->whereDate('products.created_at', '>=', $request->input('created_from')))
            ->when($request->filled('created_to'), fn ($q) => $q->whereDate('products.created_at', '<=', $request->input('created_to')));

        if ($request->input('stock_status') === 'out_of_stock') {
            $query->whereDoesntHave('variants.balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0'));
        } elseif ($request->input('stock_status') === 'low_stock') {
            $query->whereHas('variants', fn ($variant) => $variant->whereHas('balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')));
        } elseif ($request->input('stock_status') === 'in_stock') {
            $query->whereHas('variants.balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0'));
        }

        $sort = in_array($request->input('sort'), ['name', 'created_at', 'status', 'selling_price', 'available_stock'], true) ? $request->input('sort') : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        if ($sort === 'selling_price') {
            $query->orderBy(ProductVariant::query()->select('selling_price')->whereColumn('product_variants.product_id', 'products.id')->orderByDesc('is_default')->orderBy('id')->limit(1), $direction);
        } elseif ($sort === 'available_stock') {
            $query->orderBy(ProductVariant::query()->selectRaw('coalesce(sum(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity), 0)')->join('inventory_balances', 'inventory_balances.product_variant_id', '=', 'product_variants.id')->whereColumn('product_variants.product_id', 'products.id')->whereIn('inventory_balances.inventory_location_id', $locations), $direction);
        } else {
            $query->orderBy('products.'.$sort, $direction);
        }
        $products = $query->orderBy('products.id')->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return response()->json(['success' => true, 'data' => ProductResource::collection($products), 'meta' => [
            'total' => $products->total(), 'per_page' => $products->perPage(), 'current_page' => $products->currentPage(), 'last_page' => $products->lastPage(),
        ]]);
    }

    public function store(CreateProductRequest $request, TenantContext $context, ProductService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $data['source_locale'] ??= $request->input('lang', app()->getLocale());
        $product = $service->create($data, $context->tenant()->id, $request->user());
        $auditLogger->record('inventory.product_created', $request->user(), $context->tenant(), $product);

        return response()->json(['success' => true, 'data' => new ProductResource($product)], 201);
    }

    public function show(Product $product, TenantContext $context, ReportScopeService $scope, Request $request): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('view', $product);
        $locationIds = $scope->locationIds($request->user(), $context->tenant());

        return response()->json(['success' => true, 'data' => new ProductResource($product->load([
            'category', 'images', 'variants',
            'variants.balances' => fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds),
        ]))]);
    }

    public function update(UpdateProductRequest $request, Product $product, TenantContext $context, ProductService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('manage', $product);
        $data = $request->validated();
        $data['source_locale'] ??= $request->input('lang', app()->getLocale());
        $product = $service->update($product, $data, $request->user());
        $auditLogger->record('inventory.product_updated', $request->user(), $context->tenant(), $product);

        return response()->json(['success' => true, 'data' => new ProductResource($product)]);
    }

    public function bulkStatus(Request $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'uuid'],
            'status' => ['required', 'in:active,inactive'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $results = [];
        foreach (array_values(array_unique($data['ids'])) as $uuid) {
            $product = Product::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $uuid)->first();
            if (! $product) {
                $results[] = ['id' => $uuid, 'status' => 'failed', 'message' => 'Product is not available in this business.'];

                continue;
            }

            try {
                Gate::authorize('manage', $product);
                if ($product->status !== $data['status']) {
                    $product->update(['status' => $data['status']]);
                    $auditLogger->record('inventory.product_status_bulk_changed', $request->user(), $context->tenant(), $product, [
                        'status' => $data['status'],
                        'reason' => $data['reason'] ?? null,
                    ]);
                }
                $results[] = ['id' => $product->uuid, 'status' => 'updated', 'product_status' => $product->status];
            } catch (Throwable) {
                $results[] = ['id' => $uuid, 'status' => 'failed', 'message' => 'You are not authorized to manage this product.'];
            }
        }

        return response()->json(['success' => true, 'data' => [
            'results' => $results,
            'updated' => collect($results)->where('status', 'updated')->count(),
            'failed' => collect($results)->where('status', 'failed')->count(),
        ]]);
    }

    public function exportSelected(Request $request, TenantContext $context, AuditLogger $auditLogger): StreamedResponse|JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'uuid']]);
        $products = Product::query()->where('tenant_id', $context->tenant()->id)->whereIn('uuid', array_values(array_unique($data['ids'])))->with(['category', 'variants'])->orderBy('id')->get();

        $authorized = $products->filter(fn (Product $product): bool => Gate::allows('view', $product));
        $auditLogger->record('inventory.products_exported', $request->user(), $context->tenant(), metadata: ['scope' => 'selected', 'count' => $authorized->count()]);

        return response()->streamDownload(function () use ($authorized): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Product ID', 'Name', 'SKU', 'Barcode', 'Category', 'Brand', 'Selling Price', 'Status']);
            foreach ($authorized as $product) {
                $variant = $product->variants->firstWhere('is_default', true) ?: $product->variants->first();
                fputcsv($handle, [
                    $product->uuid,
                    $this->csvSafe($product->name),
                    $this->csvSafe($variant?->sku),
                    $this->csvSafe($variant?->barcode),
                    $this->csvSafe($product->category?->name),
                    $this->csvSafe($product->brand),
                    $variant?->selling_price,
                    $product->status,
                ]);
            }
            fclose($handle);
        }, 'products-selected-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroy(Product $product, TenantContext $context, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('manage', $product);
        $product->update(['status' => 'archived']);
        $auditLogger->record('inventory.product_archived', $request->user(), $context->tenant(), $product);

        return response()->json(['success' => true, 'message' => 'Product archived.']);
    }

    public function variant(CreateVariantRequest $request, Product $product, TenantContext $context, ProductService $service): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('manage', $product);

        return response()->json(['success' => true, 'data' => new ProductVariantResource($service->createVariant($product, $request->validated()))], 201);
    }

    public function images(UploadProductImagesRequest $request, Product $product, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('manage', $product);
        $files = array_values($request->file('images', []));
        $primaryIndex = $request->has('primary_index') ? (int) $request->input('primary_index') : null;
        $storedPaths = [];

        try {
            $savedProduct = DB::transaction(function () use ($files, $primaryIndex, $product, $context, &$storedPaths): Product {
                $nextSortOrder = ((int) ProductImage::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('product_id', $product->id)
                    ->max('sort_order')) + 1;

                if ($primaryIndex !== null) {
                    ProductImage::query()
                        ->where('tenant_id', $product->tenant_id)
                        ->where('product_id', $product->id)
                        ->update(['is_primary' => false]);
                }

                foreach ($files as $index => $file) {
                    $path = $file->store('tenants/'.$context->tenant()->uuid.'/products/'.$product->uuid, 'public');

                    if (! is_string($path) || $path === '') {
                        throw new \RuntimeException('The product image could not be stored.');
                    }

                    $storedPaths[] = $path;
                    $sortOffset = $primaryIndex === null
                        ? $index
                        : ($index === $primaryIndex ? 0 : ($index < $primaryIndex ? $index + 1 : $index));
                    ProductImage::create([
                        'tenant_id' => $product->tenant_id,
                        'product_id' => $product->id,
                        'storage_path' => $path,
                        'sort_order' => $nextSortOrder + $sortOffset,
                        'is_primary' => $primaryIndex === $index,
                    ]);
                }

                // A product should always have a deterministic primary image,
                // while an existing primary remains unchanged on gallery-only uploads.
                if ($primaryIndex === null && ! ProductImage::query()
                    ->where('tenant_id', $product->tenant_id)
                    ->where('product_id', $product->id)
                    ->where('is_primary', true)
                    ->exists()) {
                    ProductImage::query()
                        ->where('tenant_id', $product->tenant_id)
                        ->where('product_id', $product->id)
                        ->orderBy('sort_order')
                        ->first()?->update(['is_primary' => true]);
                }

                return $product->refresh()->load('images');
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $storedPath) {
                Storage::disk('public')->delete($storedPath);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Product images could not be saved. Please try again.',
            ], 500);
        }

        $auditLogger->record('inventory.product_images_uploaded', $request->user(), $context->tenant(), $product, ['count' => count($files)]);

        return response()->json(['success' => true, 'data' => new ProductResource($savedProduct)]);
    }

    private function assertTenant(Product $product, TenantContext $context): void
    {
        abort_unless((int) $product->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function requireManager($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }

    private function csvSafe(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
