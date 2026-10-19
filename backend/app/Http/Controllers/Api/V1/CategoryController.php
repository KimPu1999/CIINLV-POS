<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\CreateCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CategoryController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $query = $context->tenant()->categories()->with('parent')->withCount(['products', 'children'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($search) => $search->where('categories.name', 'ilike', '%'.$request->string('search').'%')->orWhere('categories.slug', 'ilike', '%'.$request->string('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('categories.status', $request->string('status')))
            ->when($request->filled('parent_id'), fn ($q) => $q->whereHas('parent', fn ($parent) => $parent->where('categories.uuid', $request->string('parent_id'))));
        $sort = in_array($request->input('sort'), ['name', 'sort_order', 'created_at', 'products_count', 'children_count'], true) ? $request->input('sort') : 'sort_order';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $categories = $query->orderBy($sort, $direction)->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => CategoryResource::collection($categories), 'meta' => ['total' => $categories->total(), 'per_page' => $categories->perPage(), 'current_page' => $categories->currentPage(), 'last_page' => $categories->lastPage()]]);
    }

    public function store(CreateCategoryRequest $request, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        $data['tenant_id'] = $context->tenant()->id;
        $data['slug'] = $this->uniqueSlug($context->tenant()->id, $data['name']);
        $data['parent_id'] = $this->parentId($data['parent_id'] ?? null, $context->tenant()->id);
        $category = Category::create($data);
        $auditLogger->record('inventory.category_created', $request->user(), $context->tenant(), $category);

        return response()->json(['success' => true, 'data' => new CategoryResource($category->load('parent'))], 201);
    }

    public function show(Category $category, TenantContext $context): JsonResponse
    {
        $this->assertTenant($category, $context);

        return response()->json(['success' => true, 'data' => new CategoryResource($category->load('parent', 'children'))]);
    }

    public function update(UpdateCategoryRequest $request, Category $category, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($category, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $data = $request->validated();
        if (isset($data['parent_id'])) {
            $parent = $this->parentId($data['parent_id'], $category->tenant_id);
            if ($parent === $category->id || $this->containsDescendant($category, $parent)) {
                throw new UnprocessableEntityHttpException('A category cannot be its own ancestor.');
            } $data['parent_id'] = $parent;
        }
        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($category->tenant_id, $data['name'], $category->id);
        }
        $category->update($data);
        $auditLogger->record('inventory.category_updated', $request->user(), $context->tenant(), $category);

        return response()->json(['success' => true, 'data' => new CategoryResource($category->refresh()->load('parent'))]);
    }

    public function destroy(Category $category, TenantContext $context, Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->assertTenant($category, $context);
        $this->requireManager($request->user(), $context->tenant()->id);
        $category->update(['status' => 'archived']);
        $auditLogger->record('inventory.category_archived', $request->user(), $context->tenant(), $category);

        return response()->json(['success' => true, 'message' => 'Category archived.']);
    }

    private function assertTenant(Category $category, TenantContext $context): void
    {
        abort_unless((int) $category->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function parentId(?string $uuid, int $tenantId): ?int
    {
        if (! $uuid) {
            return null;
        } $parent = Category::where('tenant_id', $tenantId)->where('uuid', $uuid)->first();
        if (! $parent) {
            throw new UnprocessableEntityHttpException('The parent category does not belong to this business.');
        }

        return $parent->id;
    }

    private function containsDescendant(Category $category, ?int $parentId): bool
    {
        while ($parentId) {
            if ($parentId === $category->id) {
                return true;
            } $parentId = Category::whereKey($parentId)->value('parent_id');
        }

        return false;
    }

    private function uniqueSlug(int $tenantId, string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $i = 2;
        while (Category::where('tenant_id', $tenantId)->where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function requireManager($user, int $tenantId): void
    {
        abort_unless($user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($q) => $q->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }
}
