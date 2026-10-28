<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class AssignContactInquiryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id']];
    }
}
