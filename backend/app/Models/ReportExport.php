<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ReportExport extends Model
{
    protected $fillable = ['tenant_id', 'requested_by', 'report_type', 'format', 'filters', 'status', 'file_path', 'row_count', 'expires_at', 'started_at', 'completed_at', 'failure_reason'];

    protected static function booted(): void
    {
        static::creating(fn (ReportExport $export) => $export->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['filters' => 'array', 'expires_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
