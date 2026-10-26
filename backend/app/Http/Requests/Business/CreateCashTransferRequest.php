<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class CreateCashTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_type' => ['required', 'string', 'in:fund,register'],
            'from_id' => ['required', 'uuid'],
            'to_type' => ['required', 'string', 'in:fund,register'],
            'to_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:80'],
            'reference' => ['nullable', 'string', 'max:150'],
        ];
    }
}
