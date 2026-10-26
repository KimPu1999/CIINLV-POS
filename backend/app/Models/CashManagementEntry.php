<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CashManagementEntry extends Model
{
    protected $fillable = [
        'tenant_id', 'store_id', 'fund_id', 'register_id', 'cashier_shift_id', 'entry_type', 'direction', 'amount',
        'category', 'reason', 'reference', 'transfer_group_uuid', 'approval_status', 'created_by', 'approved_by',
        'approved_at', 'reversed_entry_id', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size',
        'idempotency_key', 'created_at', 'updated_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (CashManagementEntry $entry) => $entry->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'approved_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(CashFund::class, 'fund_id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }
}
