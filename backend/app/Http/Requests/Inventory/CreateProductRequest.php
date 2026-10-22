<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_locale' => ['nullable', 'in:en,my'], 'category_id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:200'], 'slug' => ['nullable', 'string', 'max:220'], 'description' => ['nullable', 'string', 'max:5000'], 'brand' => ['nullable', 'string', 'max:100'], 'track_inventory' => ['nullable', 'boolean'], 'has_sizes' => ['nullable', 'boolean'], 'tax_exempt' => ['nullable', 'boolean'], 'inventory_location_id' => ['nullable', 'uuid'], 'variants' => ['required', 'array', 'min:1', 'max:100'], 'variants.*.sku' => ['required', 'string', 'max:100'], 'variants.*.barcode' => ['nullable', 'string', 'max:100'], 'variants.*.barcode_type' => ['nullable', Rule::in(['ean-13', 'ean-8', 'upc-a', 'code-128', 'internal', 'manual'])], 'variants.*.unit' => ['nullable', 'string', 'max:30'], 'variants.*.attributes' => ['nullable', 'array', 'max:20'], 'variants.*.is_default' => ['nullable', 'boolean'], 'variants.*.stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'], 'variants.*.purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'], 'variants.*.selling_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'], 'variants.*.reorder_level' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $variants = $this->input('variants', []);
            $hasSizes = (bool) $this->boolean('has_sizes');
            $sizes = [];
            $defaults = 0;
            $hasStock = false;
            foreach ($variants as $index => $variant) {
                $attributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
                $size = trim((string) ($attributes['size'] ?? ''));
                if ($hasSizes && $size === '') {
                    $validator->errors()->add("variants.$index.attributes.size", 'A size is required for every size variant.');
                }
                if ($size !== '') {
                    $key = mb_strtolower($size);
                    if (isset($sizes[$key])) {
                        $validator->errors()->add("variants.$index.attributes.size", 'Duplicate sizes are not allowed for one product.');
                    }
                    $sizes[$key] = true;
                }
                if ((bool) ($variant['is_default'] ?? false)) {
                    $defaults++;
                }
                if (array_key_exists('stock_quantity', $variant)) {
                    $hasStock = true;
                }
            }
            if ($defaults > 1) {
                $validator->errors()->add('variants', 'Only one default variant may be selected.');
            }
            if ($hasStock && ! $this->filled('inventory_location_id')) {
                $validator->errors()->add('inventory_location_id', 'Select an authorized inventory location when entering stock.');
            }
        });
    }
}
