<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ProductService
{
    public function __construct(private readonly BarcodeService $barcodeService, private readonly TranslationService $translations) {}

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
            $product = Product::create(['tenant_id' => $tenantId, 'category_id' => $categoryId, 'name' => $data['name'], 'slug' => $this->uniqueSlug($tenantId, $data['slug'] ?? $data['name']), 'description' => $data['description'] ?? null, 'brand' => $data['brand'] ?? null, 'status' => 'active', 'track_inventory' => $data['track_inventory'] ?? true, 'tax_exempt' => $data['tax_exempt'] ?? false, 'created_by' => $actor->getKey()]);
            foreach ($data['variants'] ?? [] as $variant) {
                $this->createVariant($product, $variant);
            }

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

        return $product->variants()->create(['tenant_id' => $tenantId, 'sku' => $sku, 'barcode' => $barcode, 'barcode_type' => $barcodeType, 'unit' => $data['unit'] ?? 'piece', 'attributes' => $data['attributes'] ?? [], 'purchase_cost' => $data['purchase_cost'] ?? 0, 'selling_price' => $data['selling_price'] ?? 0, 'reorder_level' => $data['reorder_level'] ?? 0, 'status' => 'active']);
    }

    public function update(Product $product, array $data): Product
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
        $product->update($data);

        $product = $product->load(['category', 'variants', 'images']);
        $this->translations->queueForModel($product, $sourceLocale);

        return $product;
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
