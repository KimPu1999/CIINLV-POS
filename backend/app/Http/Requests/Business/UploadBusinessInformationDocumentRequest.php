<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UploadBusinessInformationDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['registration_document', 'business_license', 'supporting_document'])],
            'document' => ['required', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(10240)],
        ];
    }
}
