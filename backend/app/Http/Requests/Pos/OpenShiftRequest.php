<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['store_id' => ['required', 'uuid'], 'register_id' => ['required', 'uuid'], 'opening_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2']];
    }
}
