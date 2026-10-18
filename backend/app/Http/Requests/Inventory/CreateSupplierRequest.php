<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class CreateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['supplier_code' => ['required', 'string', 'max:50'], 'name' => ['required', 'string', 'max:200'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:2000'], 'contact_person' => ['nullable', 'string', 'max:150'], 'notes' => ['nullable', 'string', 'max:5000']];
    }
}
