<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttendanceRecord extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'employee_profile_id', 'store_id', 'work_date', 'clock_in', 'clock_out', 'status', 'overtime_minutes', 'notes', 'created_by'];
    protected static function booted(): void { static::creating(fn (self $record) => $record->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['work_date' => 'date', 'clock_in' => 'datetime', 'clock_out' => 'datetime', 'overtime_minutes' => 'integer']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
}
