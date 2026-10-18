<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Http\JsonResponse;

class PlatformDashboardController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_businesses' => Tenant::count(),
                'active_businesses' => Tenant::where('status', 'active')->count(),
                'suspended_businesses' => Tenant::where('status', 'suspended')->count(),
                'total_business_admins' => TenantMembership::whereHas('role', fn ($query) => $query->where('name', 'business_admin'))->where('status', 'active')->distinct('user_id')->count('user_id'),
                'total_stores' => Store::count(),
            ],
        ]);
    }
}
