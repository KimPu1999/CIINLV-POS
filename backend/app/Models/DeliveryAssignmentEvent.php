<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAssignmentEvent extends Model
{
    protected $fillable = ['tenant_id', 'marketplace_shipment_id', 'delivery_staff_profile_id', 'previous_staff_profile_id', 'event_type', 'reason', 'notes', 'actor_user_id', 'event_time', 'metadata'];

    protected function casts(): array
    {
        return ['event_time' => 'datetime', 'metadata' => 'array'];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(MarketplaceShipment::class, 'marketplace_shipment_id'); }
    public function staff(): BelongsTo { return $this->belongsTo(DeliveryStaffProfile::class, 'delivery_staff_profile_id'); }
    public function previousStaff(): BelongsTo { return $this->belongsTo(DeliveryStaffProfile::class, 'previous_staff_profile_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
