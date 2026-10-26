<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class CreateCashFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'store_id' => ['nullable', 'uuid', 'exists:stores,uuid'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'approval_limit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ];
    }
}
