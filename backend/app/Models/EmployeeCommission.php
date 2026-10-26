<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmployeeCommission extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'employee_profile_id', 'sale_id', 'commission_rate', 'base_amount', 'commission_amount', 'status', 'source_reference', 'adjustment_reason', 'created_by'];
    protected static function booted(): void { static::creating(fn (self $record) => $record->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['commission_rate' => 'decimal:2', 'base_amount' => 'decimal:2', 'commission_amount' => 'decimal:2']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
}
