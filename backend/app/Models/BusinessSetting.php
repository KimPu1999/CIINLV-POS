<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'currency_code', 'timezone', 'locale', 'tax_configuration', 'receipt_configuration',
    ];

    protected function casts(): array
    {
        return [
            'tax_configuration' => 'array',
            'receipt_configuration' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
