<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\CheckoutPreviewRequest;
use App\Models\Store;
use App\Services\PosSaleService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class PosCheckoutController
{
    public function preview(CheckoutPreviewRequest $request, TenantContext $context, PosSaleService $service): JsonResponse
    {
        $store = Store::where('tenant_id', $context->tenant()->id)->where('uuid', $request->input('store_id'))->firstOrFail();

        return response()->json(['success' => true, 'data' => $service->preview($store, $request->validated(), $request->user())]);
    }
}
