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
use App\Services\AuditLogger;
use App\Services\ProductService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProductController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $products = Product::where('tenant_id', $context->tenant()->id)->with(['category', 'variants', 'images'])->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'ilike', '%'.$request->string('search').'%')->orWhereHas('variants', fn ($v) => $v->where('sku', 'ilike', '%'.$request->string('search').'%')->orWhere('barcode', $request->string('search')))))->when($request->filled('category_id'), fn ($q) => $q->whereHas('category', fn ($c) => $c->where('uuid', $request->string('category_id'))))->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->orderBy('name')->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json(['success' => true, 'data' => ProductResource::collection($products)]);
    }

    public function store(CreateProductRequest $request, TenantContext $context, ProductService $service, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $product = $service->create($request->validated(), $context->tenant()->id, $request->user());
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
        $product = $service->update($product, $request->validated());
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
