<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('primary_index') === null || $this->input('primary_index') === '') {
                return;
            }

            $index = (int) $this->input('primary_index');
            $fileCount = count($this->file('images', []));

            if ($index >= $fileCount) {
                $validator->errors()->add('primary_index', 'The selected primary image is not included in this upload.');
            }
        });
    }
}
