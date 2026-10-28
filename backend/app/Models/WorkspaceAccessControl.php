<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceAccessControl extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'access_mode', 'preferred_view', 'updated_by'];

    public const MODE_BUSINESS_ONLY = 'business_only';

    public const MODE_MARKETPLACE_ONLY = 'marketplace_only';

    public const MODE_BOTH = 'both';

    public const VIEW_BUSINESS = 'business';

    public const VIEW_MARKETPLACE = 'marketplace';

    public const VIEW_COMBINED = 'combined';

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
