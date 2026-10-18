<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:20'],
            'tax_configuration' => ['sometimes', 'array'],
            'receipt_configuration' => ['sometimes', 'array'],
        ];
    }
}
