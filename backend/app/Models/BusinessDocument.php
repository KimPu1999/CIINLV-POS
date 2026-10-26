<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessDocument extends Model
{
    protected $fillable = [
        'uuid', 'tenant_id', 'document_type', 'original_name', 'path', 'mime_type', 'size_bytes', 'uploaded_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (BusinessDocument $document): void {
            $document->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
