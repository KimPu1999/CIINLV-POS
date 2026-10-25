<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
class MarketplaceDisputeResolution extends Model
{
    protected $fillable = ['marketplace_dispute_id', 'approved_by', 'type', 'amount', 'currency_code', 'note', 'refund_status', 'refund_idempotency_key', 'provider_reference', 'approved_at'];
    protected static function booted(): void { static::creating(fn (self $resolution) => $resolution->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['amount' => 'decimal:2', 'approved_at' => 'datetime']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function dispute(): BelongsTo { return $this->belongsTo(MarketplaceDispute::class, 'marketplace_dispute_id'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
