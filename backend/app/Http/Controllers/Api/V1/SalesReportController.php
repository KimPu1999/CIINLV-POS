<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\SalesAnalyticsService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReportController
{
    public function daily(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        $request->merge(['from' => $request->input('from', now($service->timezone($context->tenant()))->toDateString()), 'to' => $request->input('to', now($service->timezone($context->tenant()))->toDateString())]);

        return $this->summary($request, $context, $service);
    }

    public function weekly(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        $now = now($service->timezone($context->tenant()));
        $request->merge(['from' => $request->input('from', $now->copy()->startOfWeek()->toDateString()), 'to' => $request->input('to', $now->copy()->endOfWeek()->toDateString())]);

        return $this->summary($request, $context, $service);
    }

    public function monthly(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        $now = now($service->timezone($context->tenant()));
        $request->merge(['from' => $request->input('from', $now->copy()->startOfMonth()->toDateString()), 'to' => $request->input('to', $now->copy()->endOfMonth()->toDateString())]);

        return $this->summary($request, $context, $service);
    }

    public function custom(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        return $this->summary($request, $context, $service);
    }

    public function byPaymentMethod(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->byPaymentMethod($request, $context->tenant())]);
    }

    public function byCashier(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->byCashier($request, $context->tenant())]);
    }

    private function summary(Request $request, TenantContext $context, SalesAnalyticsService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->summary($request, $context->tenant())]);
    }
}
