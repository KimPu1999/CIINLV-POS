<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class ReconcileCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', 'in:fund,register'],
            'source_id' => ['required', 'uuid'],
            'actual_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
