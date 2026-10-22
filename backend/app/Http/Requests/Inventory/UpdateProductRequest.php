<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_locale' => ['sometimes', 'in:en,my'], 'category_id' => ['sometimes', 'nullable', 'uuid'], 'name' => ['sometimes', 'string', 'max:200'], 'slug' => ['sometimes', 'string', 'max:220'], 'description' => ['nullable', 'string', 'max:5000'], 'brand' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', 'in:active,inactive,archived'], 'track_inventory' => ['sometimes', 'boolean'], 'has_sizes' => ['sometimes', 'boolean'], 'tax_exempt' => ['sometimes', 'boolean'], 'inventory_location_id' => ['nullable', 'uuid'], 'variants' => ['sometimes', 'array', 'min:1', 'max:100'], 'variants.*.id' => ['nullable', 'uuid'], 'variants.*.sku' => ['required_with:variants', 'string', 'max:100'], 'variants.*.barcode' => ['nullable', 'string', 'max:100'], 'variants.*.barcode_type' => ['nullable', Rule::in(['ean-13', 'ean-8', 'upc-a', 'code-128', 'internal', 'manual'])], 'variants.*.unit' => ['nullable', 'string', 'max:30'], 'variants.*.attributes' => ['nullable', 'array', 'max:20'], 'variants.*.is_default' => ['nullable', 'boolean'], 'variants.*.stock_quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999', 'decimal:0,3'], 'variants.*.purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'], 'variants.*.selling_price' => ['required_with:variants', 'numeric', 'min:0', 'max:999999999999.9999'], 'variants.*.reorder_level' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->has('variants')) {
                return;
            }
            $product = $this->route('product');
            $hasSizes = $this->has('has_sizes') ? $this->boolean('has_sizes') : (bool) $product?->has_sizes;
            $sizes = [];
            $defaults = 0;
            $hasStock = false;
            foreach ($this->input('variants', []) as $index => $variant) {
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
