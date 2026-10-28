<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceContactInquiryMessage extends Model
{
    public const TYPE_STAFF_REPLY = 'staff_reply';
    public const TYPE_INTERNAL_NOTE = 'internal_note';

    protected $fillable = [
        'marketplace_contact_inquiry_id',
        'sender_user_id',
        'message_type',
        'body',
        'delivery_status',
        'delivery_error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(MarketplaceContactInquiry::class, 'marketplace_contact_inquiry_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }
}
