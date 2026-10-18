<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Supplier extends Model
{
    protected $fillable = ['tenant_id', 'supplier_code', 'name', 'email', 'phone', 'address', 'contact_person', 'notes', 'status'];

    protected static function booted(): void
    {
        static::creating(fn (Supplier $supplier) => $supplier->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
