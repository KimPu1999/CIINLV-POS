<?php
namespace App\Http\Requests\Platform;
use Illuminate\Foundation\Http\FormRequest;
class DeleteBusinessRequest extends FormRequest { public function authorize(): bool { return $this->user()?->isSuperAdmin() ?? false; } public function rules(): array { return ['business_code' => ['required','string','max:50'],'preview_token' => ['required','string','size:64'],'confirm' => ['accepted']]; } }
