<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
class MarketplaceDisputeEvidence extends Model
{
    protected $table = 'marketplace_dispute_evidence';
    protected $fillable = ['marketplace_dispute_id', 'uploaded_by', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256'];
    protected static function booted(): void { static::creating(fn (self $evidence) => $evidence->uuid ??= (string) Str::uuid()); }
    protected function casts(): array { return ['size_bytes' => 'integer']; }
    public function getRouteKeyName(): string { return 'uuid'; }
    public function dispute(): BelongsTo { return $this->belongsTo(MarketplaceDispute::class, 'marketplace_dispute_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
