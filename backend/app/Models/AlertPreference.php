<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertPreference extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'email_enabled', 'database_enabled', 'low_stock_enabled', 'out_of_stock_enabled', 'channels'];

    protected function casts(): array
    {
        return ['email_enabled' => 'boolean', 'database_enabled' => 'boolean', 'low_stock_enabled' => 'boolean', 'out_of_stock_enabled' => 'boolean', 'channels' => 'array'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
