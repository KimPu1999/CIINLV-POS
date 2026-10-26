<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\CreateCashFundRequest;
use App\Http\Requests\Business\CreateCashManagementEntryRequest;
use App\Http\Requests\Business\CreateCashTransferRequest;
use App\Http\Requests\Business\ReconcileCashRequest;
use App\Models\CashFund;
use App\Models\CashManagementEntry;
use App\Models\CashMovement;
use App\Models\CashReconciliation;
use App\Models\CashierShift;
use App\Models\PosRegister;
use App\Services\CashManagementService;
use App\Services\PosAccessService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CashManagementController
{
    public function overview(Request $request, TenantContext $context, PosAccessService $access, CashManagementService $service): JsonResponse
    {
        $tenant = $context->tenant();
        $this->assertManager($request, $tenant->id, $access);
        $stores = $tenant->stores()->where('status', 'active')->get()->filter(fn ($store) => $access->canAccessStore($request->user(), $store));
        $storeIds = $stores->pluck('id');
        $isBusinessAdmin = $access->role($request->user(), $tenant->id) === 'business_admin';
        $funds = CashFund::with('store')->where('tenant_id', $tenant->id)->where(function ($query) use ($storeIds, $isBusinessAdmin): void {
            if ($isBusinessAdmin) {
                $query->whereNull('store_id')->orWhereIn('store_id', $storeIds);
            } else {
                $query->whereIn('store_id', $storeIds);
            }
        })->where('status', 'active')->orderBy('name')->get()->map(fn (CashFund $fund): array => $this->fundPayload($fund, $service))->values();
        $registers = PosRegister::with(['store', 'shifts' => fn ($query) => $query->where('status', 'open')->with('cashier')->latest('opened_at')])
            ->where('tenant_id', $tenant->id)->whereIn('store_id', $storeIds)->where('status', 'active')->orderBy('name')->get()
            ->map(function (PosRegister $register) use ($service): array {
                $shift = $register->shifts->first();
                return [
                    'id' => $register->uuid,
                    'name' => $register->name,
                    'store_id' => $register->store?->uuid,
                    'store_name' => $register->store?->name,
                    'balance' => $shift ? $service->registerBalance($shift) : '0.00',
                    'shift' => $shift ? ['id' => $shift->uuid, 'cashier_name' => $shift->cashier?->name, 'opened_at' => $shift->opened_at, 'expected_amount' => $service->registerBalance($shift)] : null,
                ];
            })->values();

        return response()->json(['success' => true, 'data' => ['funds' => $funds, 'registers' => $registers, 'stores' => $stores->map(fn ($store): array => ['id' => $store->uuid, 'name' => $store->name])->values(), 'permissions' => ['can_manage' => true, 'can_create_businesswide' => $isBusinessAdmin]]]);
    }

    public function history(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $tenant = $context->tenant();
        $this->assertManager($request, $tenant->id, $access);
        $perPage = (int) $request->integer('per_page', 20);
        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            throw new UnprocessableEntityHttpException('The selected per page is invalid.');
        }
        $storeIds = $tenant->stores()->where('status', 'active')->get()->filter(fn ($store) => $access->canAccessStore($request->user(), $store))->pluck('id');
        $search = trim((string) $request->input('search', ''));
        $movementQuery = DB::table('cash_movements')
            ->where('cash_movements.tenant_id', $tenant->id)->whereIn('cash_movements.store_id', $storeIds)
            ->leftJoin('pos_registers as movement_register', 'movement_register.id', '=', 'cash_movements.register_id')
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void { $inner->where('reason', 'ilike', '%'.$search.'%')->orWhere('reference_id', 'ilike', '%'.$search.'%')->orWhere('movement_type', 'ilike', '%'.$search.'%'); }))
            ->selectRaw("cash_movements.uuid, cash_movements.tenant_id, cash_movements.store_id, NULL::bigint AS fund_id, cash_movements.register_id, cash_movements.cashier_shift_id, cash_movements.movement_type AS entry_type, CASE WHEN cash_movements.movement_type IN ('cash_sale','cash_in','opening_float') THEN 'credit' ELSE 'debit' END AS direction, cash_movements.amount, NULL::varchar AS category, cash_movements.reason, cash_movements.reference_id AS reference, NULL::uuid AS transfer_group_uuid, 'approved' AS approval_status, cash_movements.created_by, cash_movements.created_at, movement_register.name AS register_name, NULL::varchar AS fund_name");
        $isBusinessAdmin = $access->role($request->user(), $tenant->id) === 'business_admin';
        $entryQuery = DB::table('cash_management_entries')
            ->where('cash_management_entries.tenant_id', $tenant->id)->where(function ($query) use ($storeIds, $isBusinessAdmin): void {
                if ($isBusinessAdmin) {
                    $query->whereNull('cash_management_entries.store_id')->orWhereIn('cash_management_entries.store_id', $storeIds);
                } else {
                    $query->whereIn('cash_management_entries.store_id', $storeIds);
                }
            })
            ->leftJoin('cash_funds as history_fund', 'history_fund.id', '=', 'cash_management_entries.fund_id')
            ->leftJoin('pos_registers as history_register', 'history_register.id', '=', 'cash_management_entries.register_id')
            ->when($search !== '', fn ($query) => $query->where(function ($inner) use ($search): void { $inner->where('reason', 'ilike', '%'.$search.'%')->orWhere('reference', 'ilike', '%'.$search.'%')->orWhere('entry_type', 'ilike', '%'.$search.'%')->orWhere('category', 'ilike', '%'.$search.'%'); }))
            ->selectRaw("cash_management_entries.uuid, cash_management_entries.tenant_id, cash_management_entries.store_id, cash_management_entries.fund_id, cash_management_entries.register_id, cash_management_entries.cashier_shift_id, cash_management_entries.entry_type, cash_management_entries.direction, cash_management_entries.amount, cash_management_entries.category, cash_management_entries.reason, cash_management_entries.reference, cash_management_entries.transfer_group_uuid, cash_management_entries.approval_status, cash_management_entries.created_by, cash_management_entries.created_at, history_register.name AS register_name, history_fund.name AS fund_name");
        $history = DB::query()->fromSub($movementQuery->unionAll($entryQuery), 'cash_history')->orderByDesc('created_at')->paginate($perPage)->withQueryString();

        return response()->json(['success' => true, 'data' => $history]);
    }

    public function reconciliations(Request $request, TenantContext $context, PosAccessService $access): JsonResponse
    {
        $tenant = $context->tenant();
        $this->assertManager($request, $tenant->id, $access);
        $perPage = (int) $request->integer('per_page', 20);
        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            throw new UnprocessableEntityHttpException('The selected per page is invalid.');
        }
        $rows = CashReconciliation::where('tenant_id', $tenant->id)->latest()->paginate($perPage)->withQueryString();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function storeFund(CreateCashFundRequest $request, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $fund = $service->createFund($context->tenant(), $request->user(), $request->validated());
        return response()->json(['success' => true, 'data' => $this->fundPayload($fund->load('store'), $service)], 201);
    }

    public function storeEntry(CreateCashManagementEntryRequest $request, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $key = $this->idempotencyKey($request);
        $entry = $service->createFundEntry($context->tenant(), $request->user(), $request->validated(), $key, $request->file('attachment'));
        return response()->json(['success' => true, 'data' => $this->entryPayload($entry->load(['fund', 'register']))], 201);
    }

    public function storeTransfer(CreateCashTransferRequest $request, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $entries = $service->transfer($context->tenant(), $request->user(), $request->validated(), $this->idempotencyKey($request));
        return response()->json(['success' => true, 'data' => collect($entries)->map(fn (CashManagementEntry $entry) => $this->entryPayload($entry))->values()], 201);
    }

    public function approve(Request $request, CashManagementEntry $entry, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $this->assertEntryTenant($entry, $context);
        $entries = $service->approve($context->tenant(), $request->user(), $entry);
        return response()->json(['success' => true, 'data' => collect($entries)->map(fn (CashManagementEntry $item) => $this->entryPayload($item))->values()]);
    }

    public function reject(Request $request, CashManagementEntry $entry, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $this->assertEntryTenant($entry, $context);
        return response()->json(['success' => true, 'data' => $this->entryPayload($service->reject($context->tenant(), $request->user(), $entry))]);
    }

    public function reverse(Request $request, CashManagementEntry $entry, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $this->assertEntryTenant($entry, $context);
        $entries = $service->reverse($context->tenant(), $request->user(), $entry, $this->idempotencyKey($request));
        return response()->json(['success' => true, 'data' => collect($entries)->map(fn (CashManagementEntry $item) => $this->entryPayload($item))->values()]);
    }

    public function reconcile(ReconcileCashRequest $request, TenantContext $context, CashManagementService $service): JsonResponse
    {
        $reconciliation = $service->reconcile($context->tenant(), $request->user(), $request->validated(), $this->idempotencyKey($request));
        return response()->json(['success' => true, 'data' => $reconciliation], 201);
    }

    public function downloadAttachment(Request $request, CashManagementEntry $entry, TenantContext $context, PosAccessService $access): mixed
    {
        $this->assertEntryTenant($entry, $context);
        $this->assertManager($request, $context->tenant()->id, $access);
        abort_unless($entry->attachment_path && Storage::disk('private')->exists($entry->attachment_path), 404, 'Cash receipt not found.');
        return Storage::disk('private')->download($entry->attachment_path, $entry->attachment_name ?: 'cash-receipt', ['Content-Type' => $entry->attachment_mime ?: 'application/octet-stream']);
    }

    private function fundPayload(CashFund $fund, CashManagementService $service): array
    {
        return ['id' => $fund->uuid, 'name' => $fund->name, 'store_id' => $fund->store?->uuid, 'store_name' => $fund->store?->name, 'opening_balance' => $fund->opening_balance, 'approval_limit' => $fund->approval_limit, 'balance' => $service->fundBalance($fund), 'status' => $fund->status];
    }

    private function entryPayload(CashManagementEntry $entry): array
    {
        return ['id' => $entry->uuid, 'entry_type' => $entry->entry_type, 'direction' => $entry->direction, 'amount' => $entry->amount, 'category' => $entry->category, 'reason' => $entry->reason, 'reference' => $entry->reference, 'transfer_group_id' => $entry->transfer_group_uuid, 'approval_status' => $entry->approval_status, 'fund_name' => $entry->fund?->name, 'register_name' => $entry->register?->name, 'attachment_name' => $entry->attachment_name, 'created_by' => $entry->created_by, 'created_at' => $entry->created_at];
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 120) {
            throw new UnprocessableEntityHttpException('Idempotency-Key is required and must be at most 120 characters.');
        }
        return $key;
    }

    private function assertManager(Request $request, int $tenantId, PosAccessService $access): void
    {
        if (! in_array($access->role($request->user(), $tenantId), ['business_admin', 'store_manager'], true)) {
            throw new AccessDeniedHttpException('Cash Management access is restricted to authorized business managers.');
        }
    }

    private function assertEntryTenant(CashManagementEntry $entry, TenantContext $context): void
    {
        if ((int) $entry->tenant_id !== (int) $context->tenant()->id) {
            throw new NotFoundHttpException('The requested cash entry was not found.');
        }
    }
}
