<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class CreateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['store_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'], 'settings' => ['nullable', 'array', 'max:20']];
    }
}
