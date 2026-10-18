<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlatformBusinessAuditController
{
    public function index(Request $request, Tenant $business): JsonResponse
    {
        Gate::authorize('manage', $business);
        $logs = AuditLog::query()->where('tenant_id', $business->getKey())->latest('created_at')->paginate(min((int) $request->input('per_page', 50), 100));

        return response()->json(['success' => true, 'data' => $logs]);
    }
}
