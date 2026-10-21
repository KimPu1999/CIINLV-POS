<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\CreateMarketplaceShipmentRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceShipmentStatusRequest;
use App\Http\Resources\MarketplaceShipmentResource;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceVendorOrder;
use App\Services\MarketplaceFulfillmentService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;

class MarketplaceVendorFulfillmentController
{
    public function createShipment(CreateMarketplaceShipmentRequest $request, string $order, TenantContext $context, MarketplaceFulfillmentService $fulfillment): JsonResponse
    {
        $vendorOrder = MarketplaceVendorOrder::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $order)->firstOrFail();
        $shipment = $fulfillment->createShipment($vendorOrder, $request->user(), $request->validated());

        return response()->json(['success' => true, 'data' => new MarketplaceShipmentResource($shipment)], 201);
    }

    public function updateShipment(UpdateMarketplaceShipmentStatusRequest $request, string $shipment, TenantContext $context, MarketplaceFulfillmentService $fulfillment): JsonResponse
    {
        $model = MarketplaceShipment::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $shipment)->firstOrFail();
        $updated = $fulfillment->transitionShipment($model, $request->user(), $request->string('status')->toString(), $request->input('description'));

        return response()->json(['success' => true, 'data' => new MarketplaceShipmentResource($updated)]);
    }
}
