<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ReportExport;
use App\Services\ReportExportService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportExportController
{
    public function store(Request $request, TenantContext $context, ReportExportService $service): JsonResponse
    {
        $request->validate(['report_type' => ['required', 'string', 'in:sales,inventory,branches'], 'format' => ['required', 'string', 'in:csv,pdf'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'store_id' => ['nullable', 'uuid']]);
        $export = $service->create($request, $context->tenant());

        return response()->json(['success' => true, 'message' => 'Report export queued.', 'data' => $this->data($export)], 202);
    }

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $rows = ReportExport::where('tenant_id', $context->tenant()->id)->where('requested_by', $request->user()->id)->latest()->paginate(25);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function show(ReportExport $export, Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeExport($export, $request, $context);

        return response()->json(['success' => true, 'data' => $this->data($export)]);
    }

    public function download(ReportExport $export, Request $request, TenantContext $context)
    {
        $this->authorizeExport($export, $request, $context);
        abort_if($export->status !== 'completed' || ! $export->expires_at || $export->expires_at->isPast() || ! $export->file_path || ! Storage::disk('local')->exists($export->file_path), 404, 'The report export is unavailable or expired.');

        return Storage::disk('local')->download($export->file_path);
    }

    private function authorizeExport(ReportExport $export, Request $request, TenantContext $context): void
    {
        abort_unless((int) $export->tenant_id === (int) $context->tenant()->id && (int) $export->requested_by === (int) $request->user()->id, 403);
    }

    private function data(ReportExport $export): array
    {
        $data = $export->toArray();
        $data['id'] = $export->uuid;

        return $data;
    }
}
