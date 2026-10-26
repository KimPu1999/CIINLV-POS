<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DeliveryCourierPartner extends Model
{
    protected $fillable = ['uuid', 'tenant_id', 'name', 'phone', 'email', 'status', 'notes', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (self $partner) => $partner->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
