<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\DatabaseHealthCheck;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

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

    public function ready(DatabaseHealthCheck $healthCheck): JsonResponse
    {
        $result = $healthCheck->run();

        return response()->json([
            'success' => $result['healthy'],
            'status' => $result['healthy'] ? 'ready' : 'not_ready',
            'checks' => $result['checks'],
        ], $result['healthy'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
