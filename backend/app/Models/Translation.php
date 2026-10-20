<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    protected $fillable = [
        'tenant_id',
        'translatable_type',
        'translatable_id',
        'locale',
        'source_locale',
        'field',
        'value',
        'source_value_hash',
        'state',
        'translated_by_user_id',
        'reviewed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function translator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'translated_by_user_id');
    }
}
