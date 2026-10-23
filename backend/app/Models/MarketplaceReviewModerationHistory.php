<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceReviewModerationHistory extends Model
{
    public $timestamps = false;

    protected $fillable = ['marketplace_review_id', 'tenant_id', 'moderator_user_id', 'previous_status', 'new_status', 'reason', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(MarketplaceReview::class, 'marketplace_review_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_user_id');
    }
}
