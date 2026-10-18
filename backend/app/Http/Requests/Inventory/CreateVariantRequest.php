<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['sku' => ['required', 'string', 'max:100'], 'barcode' => ['nullable', 'string', 'max:100'], 'barcode_type' => ['nullable', Rule::in(['ean-13', 'ean-8', 'upc-a', 'code-128', 'internal', 'manual'])], 'unit' => ['nullable', 'string', 'max:30'], 'attributes' => ['nullable', 'array', 'max:20'], 'purchase_cost' => ['nullable', 'numeric', 'min:0'], 'selling_price' => ['required', 'numeric', 'min:0'], 'reorder_level' => ['nullable', 'numeric', 'min:0']];
    }
}
