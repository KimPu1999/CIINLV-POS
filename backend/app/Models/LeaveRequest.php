<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LeaveRequest extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'employee_profile_id', 'leave_type', 'starts_on', 'ends_on', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'created_by'];
    protected static function booted(): void { static::creating(fn (self $request) => $request->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['starts_on' => 'date', 'ends_on' => 'date', 'reviewed_at' => 'datetime']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
