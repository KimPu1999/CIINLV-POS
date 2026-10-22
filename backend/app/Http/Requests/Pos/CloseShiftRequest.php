<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['actual_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2'], 'notes' => ['nullable', 'string', 'max:1000']];
    }
}
