<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BarcodeBatch extends Model
{
    protected $fillable = [
        'tenant_id', 'created_by', 'generation_mode', 'barcode_type', 'label_template',
        'requested_label_count', 'completed_label_count', 'failed_label_count', 'status',
        'options', 'source_path', 'manifest_path', 'error_summary', 'idempotency_key',
        'started_at', 'completed_at', 'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (BarcodeBatch $batch) => $batch->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'error_summary' => 'array',
            'requested_label_count' => 'integer',
            'completed_label_count' => 'integer',
            'failed_label_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
