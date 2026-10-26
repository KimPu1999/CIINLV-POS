<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class CreateCashManagementEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fund_id' => ['required', 'uuid', 'exists:cash_funds,uuid'],
            'entry_type' => ['required', 'string', 'in:cash_in,cash_out,petty_expense'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'category' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:150'],
            'attachment' => ['nullable', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(10240)],
        ];
    }
}
