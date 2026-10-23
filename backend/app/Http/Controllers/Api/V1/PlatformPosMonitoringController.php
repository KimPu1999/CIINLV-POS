<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformPosMonitoringController
{
    public function overview(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $this->audit($auditLogger, $request, 'platform.pos_monitoring_overview_viewed');
        $sales = $this->salesQuery($request);
        $completed = (clone $sales)->whereIn('sales.status', $this->completedStatuses());
        $verifiedStatuses = ['paid', 'partially_refunded', 'refunded'];
        $pendingStatuses = ['unpaid', 'pending', 'partially_paid'];
        $summary = [
            'completed_sales' => (clone $completed)->count('sales.id'),
            'verified_revenue' => (clone $completed)->whereIn('sales.payment_status', $verifiedStatuses)->sum('sales.paid_total'),
            'pending_payments' => (clone $sales)->whereIn('sales.payment_status', $pendingStatuses)->sum('sales.balance_due'),
            'refunds' => (clone $completed)->sum('sales.refunded_total'),
        ];
        $summary['net_sales'] = bcsub((string) $summary['verified_revenue'], (string) $summary['refunds'], 2);

        return response()->json(['success' => true, 'data' => ['metrics' => $summary]]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $rows = $this->salesQuery($request)
            ->with(['tenant:id,uuid,name,business_code', 'store:id,uuid,name,code', 'register:id,uuid,name,code', 'cashier:id,name,email', 'customer:id,name,email', 'items:id,uuid,sale_id,product_name_snapshot,sku_snapshot,quantity,unit_price,line_total', 'payments:id,uuid,sale_id,method,amount,status,verified_at', 'receipt:id,uuid,sale_id,receipt_number,generated_at'])
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $rows->getCollection()->map(fn (Sale $sale): array => $this->salePayload($sale))->values(), 'meta' => $this->pagination($rows)]);
    }

    public function show(Request $request, string $sale, AuditLogger $auditLogger): JsonResponse
    {
        $model = $this->salesQuery($request)->with([
            'tenant:id,uuid,name,business_code', 'store:id,uuid,name,code', 'register:id,uuid,name,code',
            'cashier:id,name,email', 'customer:id,name,email', 'items:id,uuid,sale_id,product_name_snapshot,sku_snapshot,quantity,unit_price,discount_amount,tax_amount,line_total',
            'payments:id,uuid,sale_id,method,amount,status,verified_at,provider', 'receipt:id,uuid,sale_id,receipt_number,generated_at',
        ])->where('sales.uuid', $sale)->firstOrFail();
        $this->audit($auditLogger, $request, 'platform.pos_transaction_viewed', $model);

        return response()->json(['success' => true, 'data' => $this->salePayload($model, true)]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $refunds = Refund::query()->with(['tenant:id,uuid,name,business_code', 'store:id,uuid,name,code', 'sale:id,uuid,sale_number', 'processor:id,name'])
            ->when($request->filled('business'), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $this->matchIdentifier($tenant, 'tenants', $request->string('business')->toString())))
            ->when($request->filled('branch'), fn (Builder $query) => $query->whereHas('store', fn (Builder $store) => $this->matchIdentifier($store, 'stores', $request->string('branch')->toString())))
            ->when($request->filled('cashier'), fn (Builder $query) => $query->whereHas('sale', fn (Builder $sale) => $sale->where('cashier_user_id', (int) $request->input('cashier'))))
            ->when($request->filled('status') && $request->string('status')->toString() !== 'voided', fn (Builder $query) => $query->where('refunds.status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('refunds.created_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay()))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('refunds.created_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay()))
            ->when($request->string('status')->toString() === 'voided', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->get();
        $voidRequest = $request->duplicate();
        $voidRequest->query->remove('status');
        $voids = $this->salesQuery($voidRequest)
            ->when($request->filled('status') && $request->string('status')->toString() !== 'voided', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('sales.status', 'cancelled')
            ->with(['tenant:id,uuid,name,business_code', 'store:id,uuid,name,code'])
            ->get();
        $history = $refunds->map(fn (Refund $refund): array => [
            'id' => $refund->uuid, 'type' => 'refund', 'sale_number' => $refund->sale?->sale_number, 'business' => $refund->tenant ? ['name' => $refund->tenant->name] : null,
            'branch' => $refund->store ? ['name' => $refund->store->name] : null, 'status' => $refund->status, 'method' => $refund->method,
            'amount' => $refund->amount, 'processed_by' => $refund->processor?->name, 'created_at' => $refund->created_at?->toIso8601String(),
        ])->concat($voids->map(fn (Sale $sale): array => [
            'id' => $sale->uuid, 'type' => 'void', 'sale_number' => $sale->sale_number, 'business' => $sale->tenant ? ['name' => $sale->tenant->name] : null,
            'branch' => $sale->store ? ['name' => $sale->store->name] : null, 'status' => 'voided', 'method' => null,
            'amount' => $sale->grand_total, 'processed_by' => null, 'created_at' => $sale->created_at?->toIso8601String(),
        ]))->sortByDesc('created_at')->values();
        $page = max($request->integer('page', 1), 1);
        $perPage = $this->perPage($request);

        return response()->json(['success' => true, 'data' => $history->forPage($page, $perPage)->values(), 'meta' => ['current_page' => $page, 'last_page' => max((int) ceil($history->count() / $perPage), 1), 'per_page' => $perPage, 'total' => $history->count()]]);
    }

    public function payments(Request $request): JsonResponse
    {
        $rows = Payment::query()->with(['tenant:id,uuid,name,business_code', 'store:id,uuid,name,code', 'sale:id,uuid,sale_number', 'collector:id,name'])
            ->when($request->filled('business'), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $this->matchIdentifier($tenant, 'tenants', $request->string('business')->toString())))
            ->when($request->filled('branch'), fn (Builder $query) => $query->whereHas('store', fn (Builder $store) => $this->matchIdentifier($store, 'stores', $request->string('branch')->toString())))
            ->when($request->filled('cashier'), fn (Builder $query) => $query->whereHas('sale', fn (Builder $sale) => $sale->where('cashier_user_id', (int) $request->input('cashier'))))
            ->when($request->filled('method'), fn (Builder $query) => $query->where('payments.method', $request->string('method')->toString()))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('payments.status', $request->string('status')->toString()))
            ->latest('payments.created_at')->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $rows->getCollection()->map(fn (Payment $payment): array => [
            'id' => $payment->uuid, 'sale_number' => $payment->sale?->sale_number, 'business' => $payment->tenant ? ['name' => $payment->tenant->name] : null,
            'branch' => $payment->store ? ['name' => $payment->store->name] : null, 'method' => $payment->method, 'status' => $payment->status,
            'amount' => $payment->amount, 'verified_at' => $payment->verified_at?->toIso8601String(), 'collected_by' => $payment->collector?->name,
            'created_at' => $payment->created_at?->toIso8601String(),
        ])->values(), 'meta' => $this->pagination($rows)]);
    }

    public function export(Request $request, AuditLogger $auditLogger): StreamedResponse
    {
        $this->audit($auditLogger, $request, 'platform.pos_monitoring_exported', metadata: ['filters' => $this->filterMetadata($request)]);
        $sales = $this->salesQuery($request)->with(['tenant:id,name,business_code', 'store:id,name,code', 'register:id,name,code', 'cashier:id,name', 'items:id,sale_id,product_name_snapshot,sku_snapshot,quantity,line_total', 'payments:id,sale_id,method,status,amount']);

        return response()->streamDownload(function () use ($sales): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Business', 'Branch', 'Register', 'Cashier', 'Sale Number', 'Date', 'Products', 'Subtotal', 'Discount', 'Tax', 'Total', 'Payment Method', 'Payment Status', 'Refunded', 'Balance Due', 'Receipt']);
            foreach ($sales->orderBy('sales.id')->cursor() as $sale) {
                fputcsv($handle, [
                    $sale->tenant?->name, $sale->store?->name, $sale->register?->name, $sale->cashier?->name, $sale->sale_number,
                    $sale->created_at?->toIso8601String(), $sale->items->map(fn ($item) => $item->product_name_snapshot.' x'.$item->quantity)->implode('; '),
                    $sale->subtotal, $sale->discount_total, $sale->tax_total, $sale->grand_total, $sale->payments->pluck('method')->unique()->implode(', '),
                    $sale->payment_status, $sale->refunded_total, $sale->balance_due, $sale->receipt?->receipt_number,
                ]);
            }
            fclose($handle);
        }, 'pos-transactions-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function salesQuery(Request $request): Builder
    {
        $search = trim($request->string('search')->toString());
        $sort = $request->string('sort', 'created_at')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $sortColumn = ['created_at' => 'sales.created_at', 'sale_number' => 'sales.sale_number', 'total' => 'sales.grand_total'][$sort] ?? 'sales.created_at';

        return Sale::query()
            ->when($request->filled('business'), fn (Builder $query) => $query->whereHas('tenant', fn (Builder $tenant) => $this->matchIdentifier($tenant, 'tenants', $request->string('business')->toString())))
            ->when($request->filled('branch'), fn (Builder $query) => $query->whereHas('store', fn (Builder $store) => $this->matchIdentifier($store, 'stores', $request->string('branch')->toString())))
            ->when($request->filled('cashier'), fn (Builder $query) => $query->whereHas('cashier', fn (Builder $cashier) => $cashier->where('users.id', (int) $request->input('cashier'))))
            ->when($request->filled('method'), fn (Builder $query) => $query->whereHas('payments', fn (Builder $payment) => $payment->where('method', $request->string('method')->toString())))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('sales.status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('sales.created_at', '>=', Carbon::parse($request->string('from')->toString())->startOfDay()))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('sales.created_at', '<=', Carbon::parse($request->string('to')->toString())->endOfDay()))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $nested) use ($like): void {
                    $nested->where('sales.sale_number', 'ilike', $like)
                        ->orWhereHas('tenant', fn (Builder $tenant) => $tenant->where('name', 'ilike', $like)->orWhere('business_code', 'ilike', $like))
                        ->orWhereHas('store', fn (Builder $store) => $store->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))
                        ->orWhereHas('cashier', fn (Builder $cashier) => $cashier->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like))
                        ->orWhereHas('items', fn (Builder $item) => $item->where('product_name_snapshot', 'ilike', $like)->orWhere('sku_snapshot', 'ilike', $like));
                });
            })
            ->orderBy($sortColumn, $direction);
    }

    private function salePayload(Sale $sale, bool $detail = false): array
    {
        $payload = [
            'id' => $sale->uuid, 'sale_number' => $sale->sale_number, 'created_at' => $sale->created_at?->toIso8601String(), 'completed_at' => $sale->completed_at?->toIso8601String(),
            'business' => $sale->tenant ? ['id' => $sale->tenant->uuid, 'name' => $sale->tenant->name, 'code' => $sale->tenant->business_code] : null,
            'branch' => $sale->store ? ['id' => $sale->store->uuid, 'name' => $sale->store->name, 'code' => $sale->store->code] : null,
            'register' => $sale->register ? ['id' => $sale->register->uuid, 'name' => $sale->register->name, 'code' => $sale->register->code] : null,
            'cashier' => $sale->cashier ? ['id' => $sale->cashier->id, 'name' => $sale->cashier->name, 'email' => $this->maskEmail($sale->cashier->email)] : null,
            'customer' => $sale->customer ? ['name' => $this->maskName($sale->customer->name), 'email' => $this->maskEmail($sale->customer->email)] : null,
            'status' => $sale->status, 'payment_status' => $sale->payment_status, 'currency_code' => $sale->currency_code,
            'subtotal' => $sale->subtotal, 'discount_total' => $sale->discount_total, 'tax_total' => $sale->tax_total, 'grand_total' => $sale->grand_total,
            'paid_total' => $sale->paid_total, 'balance_due' => $sale->balance_due, 'refunded_total' => $sale->refunded_total,
            'payment_methods' => $sale->payments?->pluck('method')->unique()->values() ?? [], 'receipt' => $sale->receipt ? ['number' => $sale->receipt->receipt_number, 'generated_at' => $sale->receipt->generated_at?->toIso8601String()] : null,
        ];
        if ($detail) {
            $payload['products'] = $sale->items->map(fn ($item): array => ['name' => $item->product_name_snapshot, 'sku' => $item->sku_snapshot, 'quantity' => $item->quantity, 'unit_price' => $item->unit_price, 'line_total' => $item->line_total])->values();
            $payload['payments'] = $sale->payments->map(fn ($payment): array => ['id' => $payment->uuid, 'method' => $payment->method, 'amount' => $payment->amount, 'status' => $payment->status, 'verified_at' => $payment->verified_at?->toIso8601String(), 'provider' => $payment->provider])->values();
        }

        return $payload;
    }

    private function matchIdentifier(Builder $query, string $table, string $value): void
    {
        $query->where(function (Builder $nested) use ($table, $value): void {
            $nested->where($table.'.uuid', $value)->orWhere($table.'.id', ctype_digit($value) ? (int) $value : -1);
        });
    }

    private function completedStatuses(): array
    {
        return ['completed', 'partially_refunded', 'refunded'];
    }

    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()];
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    private function filterMetadata(Request $request): array
    {
        return collect(['business', 'branch', 'cashier', 'method', 'status', 'from', 'to', 'search'])->mapWithKeys(fn (string $key): array => [$key => $request->input($key)])->filter(fn ($value): bool => $value !== null && $value !== '')->all();
    }

    private function audit(AuditLogger $auditLogger, Request $request, string $action, ?Sale $sale = null, array $metadata = []): void
    {
        $metadata['filters'] ??= $this->filterMetadata($request);
        $auditLogger->record($action, $request->user(), subject: $sale, metadata: $metadata);
    }

    private function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);

        return substr($local, 0, 1).'***@'.$domain;
    }

    private function maskName(?string $name): ?string
    {
        if (! $name) {
            return $name;
        }

        return collect(preg_split('/\s+/', trim($name)) ?: [])->map(fn (string $part): string => substr($part, 0, 1).str_repeat('*', max(strlen($part) - 1, 1)))->implode(' ');
    }
}
