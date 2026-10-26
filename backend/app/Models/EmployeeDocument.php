<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmployeeDocument extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'employee_profile_id', 'document_type', 'original_name', 'path', 'mime_type', 'size_bytes', 'uploaded_by'];
    protected static function booted(): void { static::creating(fn (self $document) => $document->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['size_bytes' => 'integer']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
