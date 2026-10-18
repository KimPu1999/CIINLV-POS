<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['type' => ['required', 'in:cash_in,cash_out'], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'reason' => ['required', 'string', 'max:500']];
    }
}
