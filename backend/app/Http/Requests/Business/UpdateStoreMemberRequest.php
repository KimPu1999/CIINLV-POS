<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role' => ['sometimes', Rule::in(['store_manager', 'inventory_manager', 'cashier', 'salesperson', 'sales_staff'])], 'status' => ['sometimes', Rule::in(['active', 'suspended', 'revoked'])]];
    }
}
