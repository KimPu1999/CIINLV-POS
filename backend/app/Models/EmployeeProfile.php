<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EmployeeProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid', 'tenant_id', 'user_id', 'employee_number', 'job_title', 'department', 'employment_type',
        'hire_date', 'employment_status', 'phone', 'address', 'emergency_contact_name', 'emergency_contact_phone',
        'notes', 'photo_path', 'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $profile) => $profile->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['hire_date' => 'date'];
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function documents(): HasMany { return $this->hasMany(EmployeeDocument::class); }
    public function attendance(): HasMany { return $this->hasMany(AttendanceRecord::class); }
    public function leaveRequests(): HasMany { return $this->hasMany(LeaveRequest::class); }
    public function payrollRecords(): HasMany { return $this->hasMany(EmployeePayrollRecord::class); }
    public function commissions(): HasMany { return $this->hasMany(EmployeeCommission::class); }
}
