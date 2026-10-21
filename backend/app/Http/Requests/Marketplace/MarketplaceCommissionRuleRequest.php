<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceCommissionRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'commission_base' => ['sometimes', Rule::in(['item_subtotal'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ];
    }
}
