<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:200'], 'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'], 'address' => ['nullable', 'string', 'max:2000']];
    }
}
