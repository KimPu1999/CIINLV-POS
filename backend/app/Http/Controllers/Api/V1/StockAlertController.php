<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AlertPreference;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\StockAlert;
use App\Services\ReportScopeService;
use App\Services\StockAlertService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockAlertController
{
    public function index(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $locationIds = $this->locationIds($request, $context, $scope);
        $status = $request->string('status')->toString();
        $rows = StockAlert::with(['location.store', 'variant.product'])
            ->where('stock_alerts.tenant_id', $tenantId)
            ->whereIn('stock_alerts.inventory_location_id', $locationIds)
            ->when($status === '', fn ($q) => $q->where(function ($inner): void {
                $inner->whereIn('stock_alerts.status', ['open', 'acknowledged'])
                    ->orWhere(fn ($s) => $s->where('stock_alerts.status', 'snoozed')->where('stock_alerts.snoozed_until', '>', now()));
            }))
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('stock_alerts.status', $status))
            ->when($request->filled('alert_type'), fn ($q) => $q->where('stock_alerts.alert_type', $request->string('alert_type')))
            ->when($request->filled('search'), function ($q) use ($request): void {
                $search = '%'.$request->string('search').'%';
                $q->where(function ($inner) use ($search): void {
                    $inner->whereHas('variant', fn ($variant) => $variant->where('product_variants.sku', 'ilike', $search)->orWhere('product_variants.barcode', 'ilike', $search)->orWhereHas('product', fn ($product) => $product->where('products.name', 'ilike', $search)))
                        ->orWhereHas('location', fn ($location) => $location->where('inventory_locations.name', 'ilike', $search)->orWhere('inventory_locations.code', 'ilike', $search));
                });
            })
            ->latest('stock_alerts.last_triggered_at')
            ->paginate(min((int) $request->input('per_page', 50), 100));
        $rows->getCollection()->transform(fn (StockAlert $alert): array => $this->alertPayload($alert, app(StockAlertService::class)));

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function statistics(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $locationIds = $this->locationIds($request, $context, $scope);
        $base = InventoryBalance::query()
            ->join('product_variants', function ($join): void {
                $join->on('product_variants.id', '=', 'inventory_balances.product_variant_id')
                    ->on('product_variants.tenant_id', '=', 'inventory_balances.tenant_id');
            })
            ->where('inventory_balances.tenant_id', $tenantId)
            ->whereIn('inventory_balances.inventory_location_id', $locationIds);
        $low = (clone $base)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) > 0')->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level');
        $out = (clone $base)->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= 0');
        $active = StockAlert::where('stock_alerts.tenant_id', $tenantId)->whereIn('stock_alerts.inventory_location_id', $locationIds)->whereIn('stock_alerts.status', ['open', 'acknowledged', 'snoozed']);

        return response()->json(['success' => true, 'data' => [
            'low_stock_items' => (clone $low)->count(),
            'out_of_stock_items' => (clone $out)->count(),
            'unique_variants' => (clone $base)->distinct('inventory_balances.product_variant_id')->count('inventory_balances.product_variant_id'),
            'locations_affected' => (clone $base)->where(function ($q): void { $q->whereRaw('(inventory_balances.on_hand_quantity - inventory_balances.reserved_quantity) <= product_variants.reorder_level'); })->distinct('inventory_balances.inventory_location_id')->count('inventory_balances.inventory_location_id'),
            'open_alerts' => (clone $active)->whereIn('stock_alerts.status', ['open', 'acknowledged'])->count(),
            'snoozed_alerts' => (clone $active)->where('stock_alerts.status', 'snoozed')->where('stock_alerts.snoozed_until', '>', now())->count(),
        ]]);
    }

    public function export(Request $request, TenantContext $context, ReportScopeService $scope, StockAlertService $service): StreamedResponse
    {
        $tenantId = $context->tenant()->id;
        $locationIds = $this->locationIds($request, $context, $scope);
        $rows = StockAlert::with(['location', 'variant.product'])->where('stock_alerts.tenant_id', $tenantId)->whereIn('stock_alerts.inventory_location_id', $locationIds)->when($request->filled('status'), fn ($q) => $q->where('stock_alerts.status', $request->string('status')))->when($request->filled('alert_type'), fn ($q) => $q->where('stock_alerts.alert_type', $request->string('alert_type')))->latest('stock_alerts.last_triggered_at')->limit(5000)->get();

        return response()->streamDownload(function () use ($rows, $service): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Alert type', 'Status', 'Product', 'SKU', 'Location', 'Available', 'Reorder level', 'Incoming', 'Recommended reorder', 'Last triggered']);
            foreach ($rows as $alert) {
                $payload = $this->alertPayload($alert, $service);
                fputcsv($handle, array_map([$this, 'csvCell'], [$payload['alert_type'], $payload['status'], $payload['variant']['product'], $payload['variant']['sku'], $payload['location']['name'], $payload['available_quantity'], $payload['threshold_quantity'], $payload['incoming_quantity'], $payload['recommended_quantity'], $payload['last_triggered_at']]));
            }
            fclose($handle);
        }, 'low-stock-alerts.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function unreadCount(Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $count = StockAlert::where('tenant_id', $context->tenant()->id)->whereIn('inventory_location_id', $scope->locationIds($request->user(), $context->tenant()))->where('status', 'open')->count();

        return response()->json(['success' => true, 'data' => ['count' => $count]]);
    }

    public function acknowledge(StockAlert $alert, Request $request, TenantContext $context, StockAlertService $service, ReportScopeService $scope): JsonResponse
    {
        abort_unless((int) $alert->tenant_id === (int) $context->tenant()->id && $scope->locationIds($request->user(), $context->tenant())->contains($alert->inventory_location_id), 403);

        $result = $service->acknowledge($alert, $request->user());
        app(\App\Services\AuditLogger::class)->record('inventory.stock_alert_acknowledged', $request->user(), $context->tenant(), $result, ['alert_type' => $result->alert_type]);

        return response()->json(['success' => true, 'data' => $this->alertPayload($result->load(['location.store', 'variant.product']), $service)]);
    }

    public function snooze(StockAlert $alert, Request $request, TenantContext $context, StockAlertService $service, ReportScopeService $scope): JsonResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:168']]);
        abort_unless((int) $alert->tenant_id === (int) $context->tenant()->id && $scope->locationIds($request->user(), $context->tenant())->contains($alert->inventory_location_id), 403);
        $result = $service->snooze($alert, $request->user(), (int) $data['hours']);
        app(\App\Services\AuditLogger::class)->record('inventory.stock_alert_snoozed', $request->user(), $context->tenant(), $result, ['hours' => (int) $data['hours']]);

        return response()->json(['success' => true, 'data' => $this->alertPayload($result->load(['location.store', 'variant.product']), $service)]);
    }

    public function reopen(StockAlert $alert, Request $request, TenantContext $context, StockAlertService $service, ReportScopeService $scope): JsonResponse
    {
        abort_unless((int) $alert->tenant_id === (int) $context->tenant()->id && $scope->locationIds($request->user(), $context->tenant())->contains($alert->inventory_location_id), 403);
        $result = $service->reopen($alert);
        app(\App\Services\AuditLogger::class)->record('inventory.stock_alert_reopened', $request->user(), $context->tenant(), $result);

        return response()->json(['success' => true, 'data' => $this->alertPayload($result->load(['location.store', 'variant.product']), $service)]);
    }

    public function preferences(Request $request, TenantContext $context): JsonResponse
    {
        $preference = AlertPreference::firstOrCreate(['tenant_id' => $context->tenant()->id, 'user_id' => $request->user()->id]);

        return response()->json(['success' => true, 'data' => $preference]);
    }

    public function updatePreferences(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['email_enabled' => ['sometimes', 'boolean'], 'database_enabled' => ['sometimes', 'boolean'], 'low_stock_enabled' => ['sometimes', 'boolean'], 'out_of_stock_enabled' => ['sometimes', 'boolean'], 'channels' => ['sometimes', 'array', 'max:10']]);
        $preference = AlertPreference::updateOrCreate(['tenant_id' => $context->tenant()->id, 'user_id' => $request->user()->id], $data);

        return response()->json(['success' => true, 'data' => $preference]);
    }

    private function alertPayload(StockAlert $alert, StockAlertService $service): array
    {
        $replenishment = $service->replenishment($alert);
        return ['id' => $alert->uuid, 'alert_type' => $alert->alert_type, 'status' => $alert->status, 'available_quantity' => $alert->available_quantity, 'threshold_quantity' => $alert->threshold_quantity, 'incoming_quantity' => $replenishment['incoming_quantity'], 'target_quantity' => $replenishment['target_quantity'], 'recommended_quantity' => $replenishment['recommended_quantity'], 'first_triggered_at' => $alert->first_triggered_at, 'last_triggered_at' => $alert->last_triggered_at, 'snoozed_until' => $alert->snoozed_until, 'location' => ['id' => $alert->location?->uuid, 'name' => $alert->location?->name, 'code' => $alert->location?->code, 'location_type' => $alert->location?->location_type, 'store' => $alert->location?->store?->name], 'variant' => ['id' => $alert->variant?->uuid, 'sku' => $alert->variant?->sku, 'barcode' => $alert->variant?->barcode, 'product' => $alert->variant?->product?->name]];
    }

    private function locationIds(Request $request, TenantContext $context, ReportScopeService $scope): \Illuminate\Support\Collection
    {
        $ids = $scope->locationIds($request->user(), $context->tenant(), $request->input('store_id'));
        if (! $request->filled('location_id')) {
            return $ids;
        }

        $requested = InventoryLocation::where('tenant_id', $context->tenant()->id)->where('uuid', $request->string('location_id'))->pluck('id');

        return $ids->intersect($requested)->values();
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return in_array($value[0] ?? '', ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
