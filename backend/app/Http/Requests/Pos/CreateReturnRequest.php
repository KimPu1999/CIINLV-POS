<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

class CreateReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.sale_item_id' => ['required', 'uuid'], 'items.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'], 'items.*.restock' => ['required', 'boolean'], 'items.*.disposition' => ['required', 'in:sellable,damaged,expired,destroyed']];
    }
}
