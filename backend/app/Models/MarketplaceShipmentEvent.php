<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceShipmentEvent extends Model
{
    protected $fillable = ['marketplace_shipment_id', 'status', 'description', 'event_time', 'actor_user_id'];

    protected function casts(): array
    {
        return ['event_time' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(MarketplaceShipment::class, 'marketplace_shipment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
