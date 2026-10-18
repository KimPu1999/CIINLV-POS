<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class CreateSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['store_id' => ['required', 'uuid'], 'register_id' => ['required', 'uuid'], 'items' => ['required', 'array', 'min:1', 'max:200'], 'items.*.variant_id' => ['required', 'uuid'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'], 'discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'], 'discount_label' => ['nullable', 'string', 'max:120'], 'customer_id' => ['nullable', 'integer', 'min:1']];
    }
}
