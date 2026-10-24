<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaqEntry extends Model
{
    protected $fillable = ['category', 'question', 'answer', 'locale', 'status', 'display_order', 'created_by', 'updated_by', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
