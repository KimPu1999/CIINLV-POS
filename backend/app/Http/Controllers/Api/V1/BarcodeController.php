<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ProductVariantResource;
use App\Models\ProductVariant;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class BarcodeController
{
    public function show(string $barcode, TenantContext $context): JsonResponse
    {
        $variant = ProductVariant::where('tenant_id', $context->tenant()->id)->where('barcode', $barcode)->where('status', 'active')->with('product')->first();
        abort_unless($variant, 404, 'No active product variant matches this barcode.');

        return response()->json(['success' => true, 'data' => new ProductVariantResource($variant)]);
    }
}
