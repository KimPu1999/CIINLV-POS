<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'application' => 'CIINLV POS',
            'status' => 'online',
            'version' => '1.0.0',
        ]);
    }
}
