<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyDocument extends Model
{
    protected $fillable = ['type', 'version', 'locale', 'title', 'content', 'status', 'effective_at', 'published_by', 'published_at', 'created_by', 'archived_at'];

    protected function casts(): array
    {
        return ['effective_at' => 'datetime', 'published_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
