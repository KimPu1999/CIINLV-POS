<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmployeePayrollRecord extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'employee_profile_id', 'period_start', 'period_end', 'base_salary', 'allowances', 'deductions', 'net_salary', 'currency_code', 'status', 'approved_by', 'approved_at', 'notes'];
    protected static function booted(): void { static::creating(fn (self $record) => $record->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['period_start' => 'date', 'period_end' => 'date', 'base_salary' => 'decimal:2', 'allowances' => 'decimal:2', 'deductions' => 'decimal:2', 'net_salary' => 'decimal:2', 'approved_at' => 'datetime']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
