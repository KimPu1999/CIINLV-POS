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
        $receipt = $service->ensure($sale, $request->user());

        return response()->json(['success' => true, 'data' => ['receipt' => new ReceiptResource($receipt), 'content' => $service->data($sale->fresh(['receipt']))]]);
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
        $service->ensure($sale, $request->user(), $format);

        return response($service->pdf($sale->fresh(['receipt']), $format), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$sale->sale_number.'.pdf"']);
    }

    private function assertTenant(Sale $sale, TenantContext $context): void
    {
        if ((int) $sale->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested sale was not found.');
        }
    }
}
