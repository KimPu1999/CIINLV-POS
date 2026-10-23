<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvoiceController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $invoices = Invoice::query()->with(['sale', 'store', 'payments'])->where('tenant_id', $context->tenant()->id)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->when($request->filled('search'), fn ($q) => $q->where('invoice_number', 'ilike', '%'.$request->string('search').'%'))->latest()->paginate(min(max((int) $request->input('per_page', 50), 1), 100));

        return response()->json(['success' => true, 'data' => InvoiceResource::collection($invoices), 'meta' => ['total' => $invoices->total(), 'current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage()]]);
    }

    public function show(Invoice $invoice, TenantContext $context): JsonResponse
    {
        abort_unless((int) $invoice->tenant_id === (int) $context->tenant()->id, 404);

        return response()->json(['success' => true, 'data' => new InvoiceResource($invoice->load(['sale', 'store', 'payments']))]);
    }
}
