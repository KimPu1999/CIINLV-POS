<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PlatformMonitoringService;
use Illuminate\Http\JsonResponse;

class PlatformMonitoringController
{
    public function __invoke(PlatformMonitoringService $monitoring): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $monitoring->overview(),
        ]);
    }
}
