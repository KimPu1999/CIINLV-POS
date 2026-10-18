<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\AddPaymentRequest;
use App\Http\Requests\Pos\VerifyPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\PosPaymentService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosPaymentController
{
    public function index(Sale $sale, TenantContext $context, Request $request): JsonResponse
    {
        $this->assertSaleTenant($sale, $context);
        Gate::forUser($request->user())->authorize('view', $sale);

        return response()->json(['success' => true, 'data' => PaymentResource::collection($sale->payments()->latest()->get())]);
    }

    public function store(AddPaymentRequest $request, Sale $sale, TenantContext $context, PosPaymentService $service): JsonResponse
    {
        $this->assertSaleTenant($sale, $context);
        Gate::forUser($request->user())->authorize('create', [Payment::class, $sale]);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required when recording a payment.');
        }
        $payment = $service->add($sale, $request->validated(), $request->user(), $key);

        return response()->json(['success' => true, 'data' => new PaymentResource($payment)], 201);
    }

    public function verify(VerifyPaymentRequest $request, Payment $payment, TenantContext $context, PosPaymentService $service): JsonResponse
    {
        $this->assertPaymentTenant($payment, $context);
        Gate::forUser($request->user())->authorize('verify', $payment);
        $payment = $service->verify($payment, $request->validated(), $request->user());

        return response()->json(['success' => true, 'data' => new PaymentResource($payment)]);
    }

    private function assertSaleTenant(Sale $sale, TenantContext $context): void
    {
        if ((int) $sale->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested sale was not found.');
        }
    }

    private function assertPaymentTenant(Payment $payment, TenantContext $context): void
    {
        if ((int) $payment->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested payment was not found.');
        }
    }
}
