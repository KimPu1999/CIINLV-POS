<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardLayout extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'user_id', 'name', 'layout', 'is_default'];

    protected static function booted(): void
    {
        static::creating(fn (DashboardLayout $layout) => $layout->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['layout' => 'array', 'is_default' => 'boolean'];
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
