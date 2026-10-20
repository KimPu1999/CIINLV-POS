<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['source_locale' => ['sometimes', 'in:en,my'], 'category_id' => ['sometimes', 'nullable', 'uuid'], 'name' => ['sometimes', 'string', 'max:200'], 'slug' => ['sometimes', 'string', 'max:220'], 'description' => ['nullable', 'string', 'max:5000'], 'brand' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', 'in:active,inactive,archived'], 'track_inventory' => ['sometimes', 'boolean'], 'tax_exempt' => ['sometimes', 'boolean']];
    }
}
