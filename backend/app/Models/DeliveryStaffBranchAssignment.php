<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryStaffBranchAssignment extends Model
{
    protected $fillable = ['tenant_id', 'delivery_staff_profile_id', 'store_id', 'status', 'assigned_by', 'assigned_at', 'revoked_at'];
    protected function casts(): array { return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function staff(): BelongsTo { return $this->belongsTo(DeliveryStaffProfile::class, 'delivery_staff_profile_id'); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
}
