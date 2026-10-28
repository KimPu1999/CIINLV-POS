<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplaceContactInquiry extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const DELIVERY_NOT_CONFIGURED = 'not_configured';
    public const DELIVERY_QUEUED = 'queued';
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'marketplace_store_id',
        'name',
        'email',
        'phone',
        'subject',
        'message',
        'status',
        'assigned_to_user_id',
        'read_at',
        'read_by_user_id',
        'admin_notification_status',
        'admin_notification_error',
        'admin_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'admin_notified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function readBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketplaceContactInquiryMessage::class);
    }
}
