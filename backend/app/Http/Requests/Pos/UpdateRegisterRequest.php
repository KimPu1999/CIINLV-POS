<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['store_id' => ['sometimes', 'uuid'], 'name' => ['sometimes', 'string', 'max:120'], 'code' => ['sometimes', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'], 'status' => ['sometimes', 'in:active,inactive,suspended'], 'settings' => ['sometimes', 'array', 'max:20']];
    }
}
