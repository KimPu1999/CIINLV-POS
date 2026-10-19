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
        return ['receipt_prefix' => ['nullable', 'string', 'max:20'], 'allow_negative_stock' => ['sometimes', 'boolean'], 'tax_configuration' => ['nullable', 'array', 'max:20'], 'tax_configuration.rate' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'tax_configuration.default_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'tax_configuration.mode' => ['sometimes', 'in:inclusive,exclusive'], 'receipt_configuration' => ['nullable', 'array', 'max:20'], 'dashboard_preferences' => ['nullable', 'array', 'max:20']];
    }
}
