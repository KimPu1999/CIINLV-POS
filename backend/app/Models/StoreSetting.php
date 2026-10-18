<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'store_id', 'receipt_prefix', 'allow_negative_stock', 'tax_configuration',
        'receipt_configuration', 'dashboard_preferences',
    ];

    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'tax_configuration' => 'array',
            'receipt_configuration' => 'array',
            'dashboard_preferences' => 'array',
        ];
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
