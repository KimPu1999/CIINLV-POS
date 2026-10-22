<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    protected $fillable = ['tenant_id', 'store_id', 'sale_id', 'invoice_number', 'status', 'currency_code', 'subtotal', 'tax_total', 'grand_total', 'paid_total', 'balance_due', 'issued_at', 'due_at', 'metadata'];
    protected static function booted(): void { static::creating(fn (Invoice $invoice) => $invoice->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['subtotal' => 'decimal:2', 'tax_total' => 'decimal:2', 'grand_total' => 'decimal:2', 'paid_total' => 'decimal:2', 'balance_due' => 'decimal:2', 'issued_at' => 'datetime', 'due_at' => 'datetime', 'metadata' => 'array']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function sale(): BelongsTo { return $this->belongsTo(Sale::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
