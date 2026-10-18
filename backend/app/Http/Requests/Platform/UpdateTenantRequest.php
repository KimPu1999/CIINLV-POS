<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:100', 'alpha_dash', Rule::unique('tenants', 'slug')->ignore($tenant?->getKey())],
            'subscription_status' => ['sometimes', Rule::in(['trial', 'active', 'past_due', 'cancelled'])],
        ];
    }
}
