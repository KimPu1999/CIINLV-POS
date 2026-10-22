<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ReceiptResource;
use App\Models\Sale;
use App\Services\ReceiptService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PosReceiptController
{
    public function show(Sale $sale, TenantContext $context, Request $request, ReceiptService $service): JsonResponse
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('view', $sale);
        if ($sale->status !== 'completed' && ! in_array($sale->status, ['partially_refunded', 'refunded'], true)) {
            throw new UnprocessableEntityHttpException('A receipt is available only after sale completion.');
        }
        $sale->loadMissing('receipt');
        $receipt = $sale->receipt;

        return response()->json(['success' => true, 'data' => ['receipt' => $receipt ? new ReceiptResource($receipt) : null, 'content' => $service->data($sale)]]);
    }

    public function pdf(Sale $sale, TenantContext $context, Request $request, ReceiptService $service): Response
    {
        $this->assertTenant($sale, $context);
        Gate::forUser($request->user())->authorize('view', $sale);
        if (! in_array($sale->status, ['completed', 'partially_refunded', 'refunded'], true)) {
            throw new UnprocessableEntityHttpException('A receipt is available only after sale completion.');
        }
        $format = $request->string('format', '80mm')->toString();
        if (! in_array($format, ['58mm', '80mm', 'a4'], true)) {
            throw new UnprocessableEntityHttpException('Unsupported receipt format.');
        }
        $sale->loadMissing('receipt');

        return response($service->pdf($sale, $format), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$sale->sale_number.'.pdf"']);
    }

    private function assertTenant(Sale $sale, TenantContext $context): void
    {
        if ((int) $sale->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested sale was not found.');
        }
    }
}
