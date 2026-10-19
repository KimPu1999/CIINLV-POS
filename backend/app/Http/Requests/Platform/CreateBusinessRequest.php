<?php

namespace App\Http\Requests\Platform;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;

class CreateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Tenant::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'business_code' => strtoupper(trim((string) $this->input('business_code'))),
            'admin_email' => strtolower(trim((string) $this->input('admin_email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'business_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_-]{2,49}$/'],
            'business_email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email:rfc', 'max:255'],
            'country' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['nullable', 'timezone'],
            'locale' => ['nullable', 'string', 'max:20'],
            'tax_configuration' => ['nullable', 'array'],
            'receipt_configuration' => ['nullable', 'array'],
        ];
    }
}
