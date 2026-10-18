<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class UploadProductImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['images' => ['required', 'array', 'max:10'], 'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], 'primary_index' => ['nullable', 'integer', 'min:0', 'max:9']];
    }
}
