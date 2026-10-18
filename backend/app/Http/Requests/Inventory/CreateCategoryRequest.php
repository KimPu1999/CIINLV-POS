<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'parent_id' => ['nullable', 'uuid'], 'description' => ['nullable', 'string', 'max:2000'], 'image_path' => ['nullable', 'string', 'max:500'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000']];
    }
}
