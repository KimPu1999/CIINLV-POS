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
use App\Models\InventoryLocation;
use App\Services\ReportScopeService;
use App\Services\AuditLogger;
use App\Services\ProductService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductController
{
    public function index(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $tenant = $context->tenant();
        $query = Product::query()->where('products.tenant_id', $tenant->id);
        $locations = null;

        if ($request->filled('branch_id')) {
            $storeIds = $scope->storeIds($request->user(), $tenant, (string) $request->input('branch_id'));
            $locations = InventoryLocation::query()->where('inventory_locations.tenant_id', $tenant->id)->whereIn('inventory_locations.store_id', $storeIds)->pluck('id');
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
            'variants.balances' => fn ($balance) => $locations === null ? $balance->where('inventory_balances.tenant_id', $tenant->id) : $balance->whereIn('inventory_balances.inventory_location_id', $locations),
        ])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('products.name', 'ilike', '%'.$request->string('search').'%')->orWhere('products.brand', 'ilike', '%'.$request->string('search').'%')->orWhereHas('variants', fn ($v) => $v->where('product_variants.sku', 'ilike', '%'.$request->string('search').'%')->orWhere('product_variants.barcode', $request->string('search')))))
            ->when($request->filled('category_id'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('categories.uuid', $request->string('category_id'))))
            ->when($request->filled('brand'), fn ($q) => $q->where('products.brand', $request->string('brand')))
            ->when($request->filled('status'), fn ($q) => $q->where('products.status', $request->string('status')))
            ->when($request->filled('min_price'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('product_variants.selling_price', '>=', $request->input('min_price'))))
            ->when($request->filled('max_price'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('product_variants.selling_price', '<=', $request->input('max_price'))));

        if ($request->input('stock_status') === 'out_of_stock') {
            $query->whereDoesntHave('variants.balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations ?? InventoryLocation::where('tenant_id', $tenant->id)->pluck('id'))->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0'));
        } elseif ($request->input('stock_status') === 'low_stock') {
            $query->whereHas('variants', fn ($variant) => $variant->whereHas('balances', fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locations ?? InventoryLocation::where('tenant_id', $tenant->id)->pluck('id'))->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level')));
        }

        $sort = in_array($request->input('sort'), ['name', 'created_at', 'status'], true) ? $request->input('sort') : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $products = $query->orderBy('products.'.$sort, $direction)->paginate(min((int) $request->input('per_page', 25), 100));

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

    public function show(Product $product, TenantContext $context): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('view', $product);

        return response()->json(['success' => true, 'data' => new ProductResource($product->load(['category', 'variants', 'images']))]);
    }

    public function update(UpdateProductRequest $request, Product $product, TenantContext $context, ProductService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($product, $context);
        Gate::authorize('manage', $product);
        $data = $request->validated();
        $data['source_locale'] ??= $request->input('lang', app()->getLocale());
        $product = $service->update($product, $data);
        $auditLogger->record('inventory.product_updated', $request->user(), $context->tenant(), $product);

        return response()->json(['success' => true, 'data' => new ProductResource($product)]);
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
        $files = $request->file('images', []);
        $primary = $request->integer('primary_index');
        foreach ($files as $index => $file) {
            $path = $file->store('tenants/'.$context->tenant()->uuid.'/products/'.$product->uuid, 'public');
            if ($primary === $index) {
                ProductImage::where('product_id', $product->id)->update(['is_primary' => false]);
            } ProductImage::create(['tenant_id' => $product->tenant_id, 'product_id' => $product->id, 'storage_path' => $path, 'sort_order' => $index, 'is_primary' => $primary === $index]);
        }
        $auditLogger->record('inventory.product_images_uploaded', $request->user(), $context->tenant(), $product, ['count' => count($files)]);

        return response()->json(['success' => true, 'data' => new ProductResource($product->refresh()->load('images'))]);
    }

    private function assertTenant(Product $product, TenantContext $context): void
    {
        abort_unless((int) $product->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function requireManager($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }
}
