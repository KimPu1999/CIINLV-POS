<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Receipt extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'sale_id', 'receipt_number', 'format', 'generated_by', 'metadata', 'generated_at'];

    protected static function booted(): void
    {
        static::creating(fn (Receipt $receipt) => $receipt->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'generated_at' => 'datetime'];
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

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
