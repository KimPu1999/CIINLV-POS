<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductImage extends Model
{
    protected $fillable = ['tenant_id', 'product_id', 'storage_path', 'sort_order', 'is_primary'];

    protected static function booted(): void
    {
        static::creating(fn (ProductImage $image) => $image->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
