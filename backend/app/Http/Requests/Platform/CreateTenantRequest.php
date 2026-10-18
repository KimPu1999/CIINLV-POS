<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'alpha_dash', Rule::unique('tenants', 'slug')],
            'subscription_status' => ['nullable', Rule::in(['trial', 'active', 'past_due', 'cancelled'])],
        ];
    }
}
