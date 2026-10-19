<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadBusinessLogoRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['logo' => ['required', File::image()->types(['png', 'jpg', 'jpeg', 'webp'])->max(2048)]]; }
}
