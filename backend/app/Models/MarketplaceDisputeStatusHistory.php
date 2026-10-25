<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MarketplaceDisputeStatusHistory extends Model
{
    protected $table = 'marketplace_dispute_status_history';
    protected $fillable = ['marketplace_dispute_id', 'changed_by', 'from_status', 'to_status', 'reason'];
    public function dispute(): BelongsTo { return $this->belongsTo(MarketplaceDispute::class, 'marketplace_dispute_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'changed_by'); }
}
