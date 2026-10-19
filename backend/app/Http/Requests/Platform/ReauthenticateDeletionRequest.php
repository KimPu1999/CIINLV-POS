<?php
namespace App\Http\Requests\Platform;
use Illuminate\Foundation\Http\FormRequest;
class ReauthenticateDeletionRequest extends FormRequest { public function authorize(): bool { return $this->user()?->isSuperAdmin() ?? false; } public function rules(): array { return ['password' => ['required','string']]; } }
