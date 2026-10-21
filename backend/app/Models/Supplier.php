<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Supplier extends Model
{
    protected $fillable = ['tenant_id', 'supplier_code', 'name', 'supplier_type', 'email', 'website', 'phone', 'alternate_phone', 'address', 'city', 'region', 'postal_code', 'country', 'default_currency', 'payment_terms', 'default_lead_time_days', 'minimum_order_amount', 'tax_registration_number', 'contact_person', 'notes', 'status'];

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
