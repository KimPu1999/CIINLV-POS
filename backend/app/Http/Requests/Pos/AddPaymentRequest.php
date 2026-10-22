<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['method' => ['required', Rule::in(array_keys(config('pos.payment_methods', [])))], 'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'], 'received_amount' => ['nullable', 'numeric', 'gte:amount', 'decimal:0,2'], 'external_reference' => ['nullable', 'string', 'max:150'], 'provider' => ['nullable', 'string', 'max:80'], 'metadata' => ['nullable', 'array', 'max:20']];
    }
}
