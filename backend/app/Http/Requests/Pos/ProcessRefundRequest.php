<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class ProcessRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['method' => ['nullable', 'in:cash,card_terminal,qr,bank_transfer'], 'payment_id' => ['nullable', 'uuid'], 'external_reference' => ['nullable', 'string', 'max:150'], 'provider' => ['nullable', 'string', 'max:80']];
    }
}
