<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_locale' => ['sometimes', 'in:en,my'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
