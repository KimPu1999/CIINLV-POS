<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AcceptBusinessInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        $user = User::query()->where('email', $this->input('email'))->first();
        $passwordRequired = ! $user?->isActive();

        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'min:32'],
            'password' => $passwordRequired
                ? ['required', 'string', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()->uncompromised()]
                : ['nullable', 'string'],
            'password_confirmation' => $passwordRequired
                ? ['required', 'same:password']
                : ['nullable', 'same:password'],
        ];
    }
}
