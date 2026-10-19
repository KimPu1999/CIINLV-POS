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
        return ['name' => ['sometimes', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:2000'], 'warehouse_type' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:2000'], 'contact_phone' => ['nullable', 'string', 'max:50'], 'contact_email' => ['nullable', 'email', 'max:255'], 'city' => ['nullable', 'string', 'max:100'], 'region' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100'], 'manager_user_id' => ['nullable', 'integer'], 'status' => ['sometimes', 'in:active,inactive,archived']];
    }
}
