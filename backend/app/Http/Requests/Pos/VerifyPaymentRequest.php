<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['external_reference' => ['required', 'string', 'max:150'], 'provider' => ['required', 'string', 'max:80'], 'verification_note' => ['required', 'string', 'max:500']];
    }
}
