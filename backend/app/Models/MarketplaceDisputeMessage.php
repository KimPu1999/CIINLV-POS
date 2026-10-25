<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
class MarketplaceDisputeMessage extends Model
{
    protected $fillable = ['marketplace_dispute_id', 'sender_user_id', 'visibility', 'message'];
    protected static function booted(): void { static::creating(fn (self $message) => $message->uuid ??= (string) Str::uuid()); }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function dispute(): BelongsTo { return $this->belongsTo(MarketplaceDispute::class, 'marketplace_dispute_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
}
