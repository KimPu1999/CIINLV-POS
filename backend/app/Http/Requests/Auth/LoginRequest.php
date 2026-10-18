<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'client_type' => $this->input('client_type', $this->header('X-Client-Type', 'web')),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
            'client_type' => ['required', Rule::in(['web', 'mobile'])],
            'device_name' => ['required_if:client_type,mobile', 'string', 'max:100'],
        ];
    }
}
