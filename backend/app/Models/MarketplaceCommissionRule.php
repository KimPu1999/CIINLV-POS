<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MarketplaceCommissionRule extends Model
{
    protected $fillable = ['tenant_id', 'rate', 'commission_base', 'status', 'effective_from', 'effective_until', 'created_by', 'updated_by'];

    protected static function booted(): void
    {
        static::creating(fn (MarketplaceCommissionRule $rule) => $rule->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'effective_from' => 'datetime', 'effective_until' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
