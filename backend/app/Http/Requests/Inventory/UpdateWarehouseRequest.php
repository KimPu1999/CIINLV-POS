<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:200'], 'address' => ['nullable', 'string', 'max:2000'], 'status' => ['sometimes', 'in:active,inactive']];
    }
}
