<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\AddMarketplaceCartItemRequest;
use App\Http\Requests\Marketplace\MarketplaceCancellationRequest;
use App\Http\Requests\Marketplace\MarketplaceCheckoutRequest;
use App\Http\Requests\Marketplace\MarketplaceReturnRequest;
use App\Http\Requests\Marketplace\UpdateMarketplaceCartItemRequest;
use App\Http\Resources\MarketplaceCartResource;
use App\Http\Resources\MarketplaceOrderResource;
use App\Http\Resources\MarketplaceCancellationRequestResource;
use App\Http\Resources\MarketplaceReturnRequestResource;
use App\Models\MarketplaceOrder;
use App\Services\MarketplaceCartService;
use App\Services\MarketplaceCheckoutService;
use App\Services\MarketplaceOrderRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceCustomerCheckoutController
{
    public function cart(Request $request, MarketplaceCartService $carts): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new MarketplaceCartResource($carts->get($request->user()))]);
    }

    public function addCartItem(AddMarketplaceCartItemRequest $request, MarketplaceCartService $carts): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new MarketplaceCartResource($carts->add($request->user(), $request->validated()))], 201);
    }

    public function updateCartItem(UpdateMarketplaceCartItemRequest $request, string $item, MarketplaceCartService $carts): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new MarketplaceCartResource($carts->update($request->user(), $item, $request->validated()))]);
    }

    public function removeCartItem(Request $request, string $item, MarketplaceCartService $carts): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new MarketplaceCartResource($carts->remove($request->user(), $item))]);
    }

    public function clearCart(Request $request, MarketplaceCartService $carts): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new MarketplaceCartResource($carts->clear($request->user()))]);
    }

    public function quote(MarketplaceCheckoutRequest $request, MarketplaceCheckoutService $checkout): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $checkout->quote($request->user(), $request->validated())]);
    }

    public function checkout(MarketplaceCheckoutRequest $request, MarketplaceCheckoutService $checkout): JsonResponse
    {
        $result = $checkout->checkout($request->user(), $request->validated(), (string) $request->header('Idempotency-Key'));

        return response()->json(['success' => true, 'idempotent_replay' => $result['replayed'], 'data' => new MarketplaceOrderResource($result['order'])], $result['replayed'] ? 200 : 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = MarketplaceOrder::query()
            ->where('customer_user_id', $request->user()->id)
            ->with(['payments', 'vendorOrders.order', 'vendorOrders.store', 'vendorOrders.items', 'vendorOrders.shipments.events.actor', 'events'])
            ->latest()
            ->paginate(min(max((int) $request->input('per_page', 20), 1), 50));

        return response()->json(['success' => true, 'data' => MarketplaceOrderResource::collection($orders), 'meta' => ['total' => $orders->total(), 'per_page' => $orders->perPage(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()]]);
    }

    public function order(Request $request, string $order): JsonResponse
    {
        $model = MarketplaceOrder::query()->where('customer_user_id', $request->user()->id)->where('uuid', $order)->with(['payments', 'vendorOrders.order', 'vendorOrders.store', 'vendorOrders.items', 'vendorOrders.shipments.events.actor', 'events'])->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceOrderResource($model)]);
    }

    public function tracking(Request $request, string $order): JsonResponse
    {
        return $this->order($request, $order);
    }

    public function requestCancellation(MarketplaceCancellationRequest $request, string $order, MarketplaceOrderRequestService $service): JsonResponse
    {
        $model = MarketplaceOrder::where('customer_user_id', $request->user()->id)->where('uuid', $order)->firstOrFail();
        $created = $service->createCancellation($model, $request->user(), $request->string('reason')->toString());

        return response()->json(['success' => true, 'data' => new MarketplaceCancellationRequestResource($created)], 201);
    }

    public function requestReturn(MarketplaceReturnRequest $request, string $order, MarketplaceOrderRequestService $service): JsonResponse
    {
        $model = MarketplaceOrder::where('customer_user_id', $request->user()->id)->where('uuid', $order)->firstOrFail();
        $created = $service->createReturn($model, $request->user(), $request->string('reason')->toString(), $request->validated('items'));

        return response()->json(['success' => true, 'data' => new MarketplaceReturnRequestResource($created)], 201);
    }
}
