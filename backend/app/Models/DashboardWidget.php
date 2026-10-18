<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardWidget extends Model
{
    protected $fillable = [
        'tenant_id', 'store_id', 'widget_key', 'title', 'position', 'width', 'height', 'is_enabled', 'settings',
    ];

    protected static function booted(): void
    {
        static::creating(fn (DashboardWidget $widget) => $widget->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'settings' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
