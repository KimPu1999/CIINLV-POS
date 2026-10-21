<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplacePayoutItem extends Model
{
    protected $fillable = ['marketplace_payout_id', 'marketplace_settlement_entry_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(MarketplacePayout::class, 'marketplace_payout_id');
    }

    public function settlementEntry(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSettlementEntry::class, 'marketplace_settlement_entry_id');
    }
}
