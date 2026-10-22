<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ProductService
{
    public function __construct(private readonly BarcodeService $barcodeService, private readonly TranslationService $translations, private readonly InventoryService $inventory) {}

    public function create(array $data, int $tenantId, User $actor): Product
    {
        $sourceLocale = $data['source_locale'] ?? TranslationService::DEFAULT_LOCALE;
        unset($data['source_locale']);
        $product = DB::transaction(function () use ($data, $tenantId, $actor): Product {
            $category = $data['category_id'] ?? null;
            $categoryId = $category ? Category::where('tenant_id', $tenantId)->where('uuid', $category)->value('id') : null;
            if ($category && $categoryId === null) {
                throw new UnprocessableEntityHttpException('The category does not belong to this business.');
            }
            $location = $this->stockLocation($data['inventory_location_id'] ?? null, $tenantId, $actor, $data['variants'] ?? []);
            $product = Product::create(['tenant_id' => $tenantId, 'category_id' => $categoryId, 'name' => $data['name'], 'slug' => $this->uniqueSlug($tenantId, $data['slug'] ?? $data['name']), 'description' => $data['description'] ?? null, 'brand' => $data['brand'] ?? null, 'status' => 'active', 'track_inventory' => $data['track_inventory'] ?? true, 'has_sizes' => $data['has_sizes'] ?? false, 'tax_exempt' => $data['tax_exempt'] ?? false, 'created_by' => $actor->getKey()]);
            $created = [];
            foreach ($data['variants'] ?? [] as $variant) {
                $created[] = $createdVariant = $this->createVariant($product, $variant);
                $this->syncStock($location, $createdVariant, $variant, $actor);
            }
            $default = $this->chooseDefault($created, null);
            $this->markDefault($product, $default);

            return $product->load(['category', 'variants', 'images']);
        });
        $this->translations->queueForModel($product, $sourceLocale);

        return $product;
    }

    public function createVariant(Product $product, array $data): ProductVariant
    {
        $tenantId = (int) $product->tenant_id;
        $sku = strtoupper(trim($data['sku']));
        if (ProductVariant::where('tenant_id', $tenantId)->where('sku', $sku)->exists()) {
            throw new ConflictHttpException('The SKU is already in use in this business.');
        }
        $barcodeType = $data['barcode_type'] ?? (! empty($data['barcode']) ? 'internal' : null);
        $barcode = $this->barcodeService->validate($data['barcode'] ?? null, $barcodeType);
        if ($barcode && ProductVariant::where('tenant_id', $tenantId)->where('barcode', $barcode)->where('status', 'active')->exists()) {
            throw new ConflictHttpException('The barcode is already in use in this business.');
        }

        $variant = $product->variants()->create(['tenant_id' => $tenantId, 'sku' => $sku, 'barcode' => $barcode, 'barcode_type' => $barcodeType, 'unit' => $data['unit'] ?? 'piece', 'attributes' => $this->attributes($data['attributes'] ?? []), 'is_default' => (bool) ($data['is_default'] ?? false), 'purchase_cost' => $data['purchase_cost'] ?? 0, 'selling_price' => $data['selling_price'] ?? 0, 'reorder_level' => $data['reorder_level'] ?? 0, 'status' => 'active']);
        if ($variant->is_default) {
            $product->variants()->where('id', '!=', $variant->id)->update(['is_default' => false]);
        }

        return $variant;
    }

    public function update(Product $product, array $data, ?User $actor = null): Product
    {
        $sourceLocale = $data['source_locale'] ?? TranslationService::DEFAULT_LOCALE;
        unset($data['source_locale']);
        if (array_key_exists('category_id', $data)) {
            $categoryUuid = $data['category_id'];
            $data['category_id'] = $categoryUuid ? Category::where('tenant_id', $product->tenant_id)->where('uuid', $categoryUuid)->value('id') : null;
            if ($categoryUuid && $data['category_id'] === null) {
                throw new UnprocessableEntityHttpException('The category does not belong to this business.');
            }
        }
        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($product->tenant_id, $data['name'], $product->id);
        }

        return DB::transaction(function () use ($product, $data, $sourceLocale, $actor): Product {
            $actor ??= request()->user();
            $location = $this->stockLocation($data['inventory_location_id'] ?? null, $product->tenant_id, $actor, $data['variants'] ?? []);
            $variants = $data['variants'] ?? null;
            unset($data['variants'], $data['inventory_location_id']);
            $product->update($data);
            if ($variants !== null) {
                $updated = $this->synchronizeVariants($product, $variants, $location, $actor);
                $this->markDefault($product, $this->chooseDefault($updated, $product->variants()->where('is_default', true)->first()));
            }

            $product = $product->load(['category', 'variants', 'images']);
            $this->translations->queueForModel($product, $sourceLocale);

            return $product;
        });
    }

    private function synchronizeVariants(Product $product, array $payload, ?InventoryLocation $location, User $actor): array
    {
        $existing = $product->variants()->get()->keyBy('uuid');
        $kept = [];
        $updated = [];
        foreach ($payload as $data) {
            $id = (string) ($data['id'] ?? '');
            $variant = $id !== '' ? $existing->get($id) : null;
            if ($id !== '' && ! $variant) {
                throw new UnprocessableEntityHttpException('The selected variant does not belong to this product.');
            }
            $variant = $variant ? $this->updateVariant($variant, $data) : $this->createVariant($product, $data);
            $kept[$variant->uuid] = true;
            $updated[] = $variant;
            $this->syncStock($location, $variant, $data, $actor);
        }
        foreach ($existing as $variant) {
            if (! isset($kept[$variant->uuid]) && $variant->status === 'active') {
                $variant->update(['status' => 'inactive']);
            }
        }

        return $updated;
    }

    private function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        $sku = strtoupper(trim($data['sku']));
        if (ProductVariant::where('tenant_id', $variant->tenant_id)->where('sku', $sku)->where('id', '!=', $variant->id)->exists()) {
            throw new ConflictHttpException('The SKU is already in use in this business.');
        }
        $barcodeType = $data['barcode_type'] ?? (! empty($data['barcode']) ? 'internal' : null);
        $barcode = $this->barcodeService->validate($data['barcode'] ?? null, $barcodeType);
        if ($barcode && ProductVariant::where('tenant_id', $variant->tenant_id)->where('barcode', $barcode)->where('id', '!=', $variant->id)->where('status', 'active')->exists()) {
            throw new ConflictHttpException('The barcode is already in use in this business.');
        }
        $variant->update(['sku' => $sku, 'barcode' => $barcode, 'barcode_type' => $barcodeType, 'unit' => $data['unit'] ?? 'piece', 'attributes' => $this->attributes($data['attributes'] ?? []), 'is_default' => (bool) ($data['is_default'] ?? false), 'purchase_cost' => $data['purchase_cost'] ?? 0, 'selling_price' => $data['selling_price'] ?? 0, 'reorder_level' => $data['reorder_level'] ?? 0, 'status' => 'active']);

        return $variant->refresh();
    }

    private function stockLocation(?string $uuid, int $tenantId, User $actor, array $variants): ?InventoryLocation
    {
        $hasStock = collect($variants)->contains(fn (array $variant): bool => array_key_exists('stock_quantity', $variant));
        if (! $hasStock) {
            return null;
        }
        $location = InventoryLocation::where('tenant_id', $tenantId)->where('uuid', $uuid)->where('status', 'active')->first();
        if (! $location) {
            throw new UnprocessableEntityHttpException('The selected inventory location is not available in this business.');
        }
        Gate::forUser($actor)->authorize('write', $location);

        return $location;
    }

    private function syncStock(?InventoryLocation $location, ProductVariant $variant, array $data, User $actor): void
    {
        $stock = $data['stock_quantity'] ?? null;
        if ($stock === null || ! $location) {
            return;
        }
        // Setting an absolute target is already idempotent under the balance
        // lock; do not reuse a historical key when the same target is chosen
        // again after a later adjustment.
        $this->inventory->setQuantity($location, $variant, (string) $stock, $actor, 'product', $variant->uuid, 'Product form stock quantity', null, (string) $variant->purchase_cost);
    }

    private function attributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }
        if (isset($attributes['size'])) {
            $attributes['size'] = trim((string) $attributes['size']);
        }

        return $attributes;
    }

    private function chooseDefault(array $variants, ?ProductVariant $fallback): ?ProductVariant
    {
        foreach ($variants as $variant) {
            if ($variant->is_default) {
                return $variant;
            }
        }

        if ($fallback && collect($variants)->contains(fn (ProductVariant $variant): bool => $variant->is($fallback))) {
            return $fallback;
        }

        return $variants[0] ?? null;
    }

    private function markDefault(Product $product, ?ProductVariant $default): void
    {
        $product->variants()->update(['is_default' => false]);
        if ($default) {
            $product->variants()->whereKey($default->getKey())->update(['is_default' => true]);
        }
    }

    private function uniqueSlug(int $tenantId, string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'product';
        $slug = $base;
        $i = 2;
        while (Product::where('tenant_id', $tenantId)->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
