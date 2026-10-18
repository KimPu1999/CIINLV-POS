<?php

namespace App\Services;

use App\Jobs\GenerateReportExport;
use App\Models\InventoryBalance;
use App\Models\ReportExport;
use App\Models\Sale;
use App\Models\Tenant;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ReportExportService
{
    public function __construct(private readonly ReportScopeService $scope, private readonly SalesAnalyticsService $sales) {}

    public function create(Request $request, Tenant $tenant): ReportExport
    {
        $type = (string) $request->input('report_type');
        $format = (string) $request->input('format', 'csv');
        if (! in_array($type, ['sales', 'inventory', 'branches'], true) || ! in_array($format, ['csv', 'pdf'], true)) {
            throw new UnprocessableEntityHttpException('Supported exports are sales, inventory, or branches in CSV or PDF format.');
        }
        $stores = $this->scope->storeIds($request->user(), $tenant, $request->input('store_id'));
        if ($stores->isEmpty()) {
            throw new UnprocessableEntityHttpException('No authorized active stores are available for this export.');
        }
        $export = ReportExport::create(['tenant_id' => $tenant->id, 'requested_by' => $request->user()->id, 'report_type' => $type, 'format' => $format, 'filters' => ['from' => $request->input('from'), 'to' => $request->input('to'), 'store_ids' => $stores->values()->all(), 'store_uuid' => $request->input('store_id'), 'include_warehouses' => $request->input('store_id') === null], 'status' => 'queued', 'expires_at' => now()->addHours(24)]);
        GenerateReportExport::dispatch($export->id)->afterCommit();

        return $export;
    }

    public function rows(ReportExport $export): array
    {
        $filters = $export->filters ?? [];
        if ($export->report_type === 'inventory') {
            return InventoryBalance::with(['location', 'variant.product'])->where('tenant_id', $export->tenant_id)->whereIn('inventory_location_id', app(ReportScopeService::class)->locationIdsForStoreIds($export->tenant_id, $filters['store_ids'] ?? [], (bool) ($filters['include_warehouses'] ?? false)))->get()->map(fn (InventoryBalance $b) => ['location' => $b->location?->name, 'sku' => $b->variant?->sku, 'product' => $b->variant?->product?->name, 'on_hand' => $b->on_hand_quantity, 'reserved' => $b->reserved_quantity, 'average_cost' => $b->average_cost, 'value' => number_format((float) $b->on_hand_quantity * (float) $b->average_cost, 4, '.', '')])->all();
        }
        if ($export->report_type === 'branches') {
            $request = Request::create('/reports', 'GET', array_filter(['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null]));
            $request->setUserResolver(fn () => $export->requester);

            return app(BranchReportingService::class)->comparison($request, $export->tenant)['rows']->all();
        }
        $query = Sale::where('tenant_id', $export->tenant_id)->whereIn('store_id', $filters['store_ids'] ?? [])->where('status', 'completed')->with('store');
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            [$from, $to] = $this->sales->range($export->tenant, $filters['from'] ?? null, $filters['to'] ?? null);
            $query->whereBetween('completed_at', [$from, $to]);
        }

        return $query->orderBy('completed_at')->get()->map(fn (Sale $sale) => ['sale_number' => $sale->sale_number, 'store' => $sale->store?->name, 'completed_at' => $sale->completed_at?->toIso8601String(), 'currency' => $sale->currency_code, 'gross_total' => $sale->grand_total, 'refunded_total' => $sale->refunded_total, 'status' => $sale->status])->all();
    }

    public function write(ReportExport $export): void
    {
        $rows = $this->rows($export);
        $path = 'exports/'.$export->tenant_id.'/'.$export->uuid.'.'.$export->format;
        if ($export->format === 'pdf') {
            $html = '<h1>CIINLV POS '.e(ucwords($export->report_type)).' Report</h1><pre>'.e(json_encode($rows, JSON_PRETTY_PRINT)).'</pre>';
            $dompdf = new Dompdf;
            $dompdf->loadHtml($html);
            $dompdf->render();
            Storage::disk('local')->put($path, $dompdf->output());
        } else {
            $handle = fopen('php://temp', 'w+');
            if ($rows !== []) {
                fputcsv($handle, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($handle, array_map([$this, 'safeCell'], array_values($row)));
                }
            }
            rewind($handle);
            Storage::disk('local')->put($path, stream_get_contents($handle));
            fclose($handle);
        }
        $export->update(['status' => 'completed', 'file_path' => $path, 'row_count' => count($rows), 'completed_at' => now()]);
    }

    private function safeCell(mixed $value): mixed
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value);

        return preg_match('/^[=+\\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
