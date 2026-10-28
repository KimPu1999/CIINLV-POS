<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DeliveryStaffProfile extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'employee_profile_id', 'store_id', 'staff_code', 'full_name', 'phone', 'email', 'emergency_contact', 'profile_photo_path', 'vehicle_type', 'vehicle_plate', 'employment_type', 'notes', 'max_active_assignments', 'availability_status', 'delivery_role', 'assignment_eligibility', 'approval_status', 'status', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (self $profile) => $profile->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function branchAssignments(): HasMany { return $this->hasMany(DeliveryStaffBranchAssignment::class, 'delivery_staff_profile_id'); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function shipments(): HasMany { return $this->hasMany(MarketplaceShipment::class, 'delivery_staff_profile_id'); }
    public function assignmentEvents(): HasMany { return $this->hasMany(DeliveryAssignmentEvent::class, 'delivery_staff_profile_id'); }
}
