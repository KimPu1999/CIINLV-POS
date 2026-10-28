<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBranchAssignment extends Model
{
    protected $fillable = ['tenant_id', 'employee_profile_id', 'store_id', 'role', 'status', 'assigned_by', 'assigned_at', 'revoked_at'];

    protected function casts(): array { return ['assigned_at' => 'datetime', 'revoked_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
}
