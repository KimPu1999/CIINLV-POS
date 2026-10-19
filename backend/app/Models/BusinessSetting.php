<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSetting extends Model
{
    protected $fillable = [
        'tenant_id', 'currency_code', 'timezone', 'locale', 'tax_configuration', 'receipt_configuration',
        'pos_configuration', 'inventory_configuration', 'payment_configuration', 'notification_configuration',
        'appearance_configuration', 'data_configuration', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'tax_configuration' => 'array',
            'receipt_configuration' => 'array',
            'pos_configuration' => 'array',
            'inventory_configuration' => 'array',
            'payment_configuration' => 'array',
            'notification_configuration' => 'array',
            'appearance_configuration' => 'array',
            'data_configuration' => 'array',
            'revision' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
