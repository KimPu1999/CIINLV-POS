<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStoreSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['receipt_prefix' => ['nullable', 'string', 'max:20'], 'allow_negative_stock' => ['sometimes', 'boolean'], 'tax_configuration' => ['nullable', 'array', 'max:20'], 'receipt_configuration' => ['nullable', 'array', 'max:20'], 'dashboard_preferences' => ['nullable', 'array', 'max:20']];
    }
}
