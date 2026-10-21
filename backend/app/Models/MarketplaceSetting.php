<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceSetting extends Model
{
    protected $fillable = ['enabled', 'seller_registration_enabled', 'seller_approval_required', 'listing_approval_required', 'maintenance_mode', 'currency_code', 'default_commission_rate', 'commission_base', 'policies', 'revision', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'seller_registration_enabled' => 'boolean', 'seller_approval_required' => 'boolean', 'listing_approval_required' => 'boolean', 'maintenance_mode' => 'boolean', 'default_commission_rate' => 'decimal:4', 'policies' => 'array', 'revision' => 'integer'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
