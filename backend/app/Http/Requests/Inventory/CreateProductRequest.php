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
        return ['source_locale' => ['nullable', 'in:en,my'], 'category_id' => ['nullable', 'uuid'], 'name' => ['required', 'string', 'max:200'], 'slug' => ['nullable', 'string', 'max:220'], 'description' => ['nullable', 'string', 'max:5000'], 'brand' => ['nullable', 'string', 'max:100'], 'track_inventory' => ['nullable', 'boolean'], 'variants' => ['required', 'array', 'min:1', 'max:100'], 'variants.*.sku' => ['required', 'string', 'max:100'], 'variants.*.barcode' => ['nullable', 'string', 'max:100'], 'variants.*.barcode_type' => ['nullable', Rule::in(['ean-13', 'ean-8', 'upc-a', 'code-128', 'internal', 'manual'])], 'variants.*.unit' => ['nullable', 'string', 'max:30'], 'variants.*.attributes' => ['nullable', 'array', 'max:20'], 'variants.*.purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999'], 'variants.*.selling_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999'], 'variants.*.reorder_level' => ['nullable', 'numeric', 'min:0', 'max:999999999999.999']];
    }
}
