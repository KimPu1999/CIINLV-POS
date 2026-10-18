<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Pos\ProcessRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Refund;
use App\Models\SaleReturn;
use App\Services\RefundService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosRefundController
{
    public function store(ProcessRefundRequest $request, SaleReturn $return, TenantContext $context, RefundService $service): JsonResponse
    {
        $this->assertTenant($return, $context);
        Gate::forUser($request->user())->authorize('process', [Refund::class, $return]);
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required when processing a refund.');
        }
        $refund = $service->process($return, $request->validated(), $request->user(), $key);

        return response()->json(['success' => true, 'data' => new RefundResource($refund)], $refund->status === 'pending' ? 202 : 201);
    }

    private function assertTenant(SaleReturn $return, TenantContext $context): void
    {
        if ((int) $return->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested return was not found.');
        }
    }
}
