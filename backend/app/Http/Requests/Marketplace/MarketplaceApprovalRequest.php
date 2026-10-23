<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketplaceApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    public function rules(): array
    {
        return [
            'approval_status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
            'reason' => ['required_if:approval_status,rejected', 'nullable', 'string', 'max:500'],
        ];
    }
}
