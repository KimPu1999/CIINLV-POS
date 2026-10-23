<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\PlatformMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformMonitoringController
{
    public function __invoke(Request $request, PlatformMonitoringService $monitoring): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $monitoring->overview([
                'queue' => $request->string('queue')->trim()->toString(),
                'status' => $request->string('status')->trim()->toString(),
                'from' => $request->string('from')->trim()->toString(),
                'to' => $request->string('to')->trim()->toString(),
            ]),
        ]);
    }
}
