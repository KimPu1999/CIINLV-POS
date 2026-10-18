<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:1000'], 'city' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100'], 'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'], 'timezone' => ['sometimes', 'timezone']];
    }
}
