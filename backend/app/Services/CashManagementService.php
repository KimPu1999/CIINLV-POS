<?php

namespace App\Services;

use App\Models\CashFund;
use App\Models\CashManagementEntry;
use App\Models\CashMovement;
use App\Models\CashierShift;
use App\Models\CashReconciliation;
use App\Models\PosRegister;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CashManagementService
{
    public function __construct(private readonly PosAccessService $access, private readonly AuditLogger $audit) {}

    public function createFund(Tenant $tenant, User $actor, array $data): CashFund
    {
        $store = $this->store($tenant, $data['store_id'] ?? null);
        $this->assertFundAccess($actor, $store, (int) $tenant->id);

        return DB::transaction(function () use ($tenant, $actor, $data, $store): CashFund {
            $fund = CashFund::create([
                'tenant_id' => $tenant->id,
                'store_id' => $store?->id,
                'name' => $data['name'],
                'fund_type' => 'petty_cash',
                'opening_balance' => $data['opening_balance'] ?? 0,
                'approval_limit' => $data['approval_limit'] ?? 0,
                'status' => 'active',
                'created_by' => $actor->id,
            ]);
            $this->audit->record('cash.fund_created', $actor, $tenant, $fund, ['fund_id' => $fund->uuid, 'store_id' => $store?->uuid]);

            return $fund;
        });
    }

    public function createFundEntry(Tenant $tenant, User $actor, array $data, string $idempotencyKey, ?UploadedFile $attachment = null): CashManagementEntry
    {
        $path = null;
        try {
            return DB::transaction(function () use ($tenant, $actor, $data, $idempotencyKey, $attachment, &$path): CashManagementEntry {
                $existing = CashManagementEntry::where('tenant_id', $tenant->id)->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    return $existing;
                }

                $fund = CashFund::where('tenant_id', $tenant->id)->where('uuid', $data['fund_id'])->lockForUpdate()->firstOrFail();
                if ($fund->status !== 'active') {
                    throw new ConflictHttpException('This petty cash fund is closed.');
                }
                $this->assertFundAccess($actor, $fund->store, (int) $tenant->id);
                $direction = in_array($data['entry_type'], ['cash_out', 'petty_expense'], true) ? 'debit' : 'credit';
                $amount = (string) $data['amount'];
                if ($direction === 'debit' && bccomp($amount, $this->fundBalance($fund), 2) > 0) {
                    throw new UnprocessableEntityHttpException('The petty cash fund has insufficient available cash.');
                }
                $status = $this->approvalStatus($actor, $fund, $amount, (int) $tenant->id);
                if ($attachment) {
                    $path = $attachment->storeAs('businesses/'.$tenant->uuid.'/cash-management/'.$fund->uuid, Str::uuid().'.'.$attachment->extension(), 'private');
                }
                $entry = CashManagementEntry::create([
                    'tenant_id' => $tenant->id,
                    'store_id' => $fund->store_id,
                    'fund_id' => $fund->id,
                    'entry_type' => $data['entry_type'],
                    'direction' => $direction,
                    'amount' => $amount,
                    'category' => $data['category'] ?? null,
                    'reason' => $data['reason'],
                    'reference' => $data['reference'] ?? null,
                    'approval_status' => $status,
                    'created_by' => $actor->id,
                    'approved_by' => $status === 'approved' ? $actor->id : null,
                    'approved_at' => $status === 'approved' ? now() : null,
                    'attachment_path' => $path,
                    'attachment_name' => $attachment?->getClientOriginalName(),
                    'attachment_mime' => $attachment?->getMimeType(),
                    'attachment_size' => $attachment?->getSize(),
                    'idempotency_key' => $idempotencyKey,
                ]);
                $this->audit->record('cash.entry_created', $actor, $tenant, $entry, ['entry_id' => $entry->uuid, 'entry_type' => $entry->entry_type, 'approval_status' => $entry->approval_status]);

                return $entry;
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }

    public function transfer(Tenant $tenant, User $actor, array $data, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($tenant, $actor, $data, $idempotencyKey): array {
            $existing = CashManagementEntry::where('tenant_id', $tenant->id)->where('idempotency_key', $idempotencyKey.':out')->first();
            if ($existing) {
                return CashManagementEntry::where('tenant_id', $tenant->id)->where('transfer_group_uuid', $existing->transfer_group_uuid)->orderBy('direction')->get()->all();
            }

            $accounts = [
                ['type' => $data['from_type'], 'uuid' => $data['from_id']],
                ['type' => $data['to_type'], 'uuid' => $data['to_id']],
            ];
            usort($accounts, fn (array $left, array $right): int => strcmp($left['type'].':'.$left['uuid'], $right['type'].':'.$right['uuid']));
            $resolved = [];
            foreach ($accounts as $account) {
                $resolved[$account['type'].':'.$account['uuid']] = $this->lockAccount($tenant, $actor, $account['type'], $account['uuid']);
            }
            $from = $resolved[$data['from_type'].':'.$data['from_id']];
            $to = $resolved[$data['to_type'].':'.$data['to_id']];
            if ($from['key'] === $to['key']) {
                throw new UnprocessableEntityHttpException('The source and destination cash accounts must be different.');
            }
            $amount = (string) $data['amount'];
            if (bccomp($amount, $this->accountBalance($from), 2) > 0) {
                throw new UnprocessableEntityHttpException('The source cash account has insufficient available cash.');
            }
            $status = $this->approvalStatusForAccount($actor, $from, $amount, (int) $tenant->id);
            $group = (string) Str::uuid();
            $common = [
                'tenant_id' => $tenant->id,
                'store_id' => $from['store_id'] ?? $to['store_id'],
                'entry_type' => 'transfer',
                'amount' => $amount,
                'category' => $data['category'] ?? 'internal_transfer',
                'reason' => $data['reason'],
                'reference' => $data['reference'] ?? null,
                'transfer_group_uuid' => $group,
                'approval_status' => $status,
                'created_by' => $actor->id,
            ];
            $debit = CashManagementEntry::create(array_merge($common, [
                'fund_id' => $from['fund_id'], 'register_id' => $from['register_id'], 'cashier_shift_id' => $from['shift_id'],
                'direction' => 'debit', 'idempotency_key' => $idempotencyKey.':out',
            ]));
            $credit = CashManagementEntry::create(array_merge($common, [
                'store_id' => $to['store_id'] ?? $from['store_id'],
                'fund_id' => $to['fund_id'], 'register_id' => $to['register_id'], 'cashier_shift_id' => $to['shift_id'],
                'direction' => 'credit', 'idempotency_key' => $idempotencyKey.':in',
            ]));
            if ($status === 'approved') {
                $debit->update(['approved_by' => $actor->id, 'approved_at' => now()]);
                $credit->update(['approved_by' => $actor->id, 'approved_at' => now()]);
            }
            $this->audit->record('cash.transfer_created', $actor, $tenant, $debit, ['transfer_group' => $group, 'amount' => $amount, 'approval_status' => $status]);

            return [$debit->fresh(), $credit->fresh()];
        });
    }

    public function approve(Tenant $tenant, User $actor, CashManagementEntry $entry): array
    {
        return DB::transaction(function () use ($tenant, $actor, $entry): array {
            $entriesQuery = CashManagementEntry::where('tenant_id', $tenant->id)->whereKey($entry->id);
            if ($entry->transfer_group_uuid) {
                $entriesQuery->orWhere(function ($query) use ($entry, $tenant): void {
                    $query->where('tenant_id', $tenant->id)->where('transfer_group_uuid', $entry->transfer_group_uuid);
                });
            }
            $entries = $entriesQuery->lockForUpdate()->get();
            $pending = $entries->where('approval_status', 'pending');
            if ($pending->isEmpty()) {
                return $entries->all();
            }
            $source = $pending->firstWhere('direction', 'debit');
            if (! $source) {
                throw new ConflictHttpException('Only a cash-out or transfer debit can be approved.');
            }
            $account = $this->lockAccountForEntry($tenant, $actor, $source);
            if (! $this->canApprove($actor, $account, (string) $source->amount)) {
                throw new AccessDeniedHttpException('You are not authorized to approve this cash movement.');
            }
            if (bccomp((string) $source->amount, $this->accountBalance($account), 2) > 0) {
                throw new UnprocessableEntityHttpException('The source cash account has insufficient available cash.');
            }
            $entries->where('approval_status', 'pending')->each(fn (CashManagementEntry $item) => $item->update(['approval_status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]));
            $this->audit->record('cash.entry_approved', $actor, $tenant, $source, ['entry_id' => $source->uuid, 'transfer_group' => $source->transfer_group_uuid]);

            return $entries->fresh()->all();
        });
    }

    public function reject(Tenant $tenant, User $actor, CashManagementEntry $entry): CashManagementEntry
    {
        return DB::transaction(function () use ($tenant, $actor, $entry): CashManagementEntry {
            $entry = CashManagementEntry::where('tenant_id', $tenant->id)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->approval_status !== 'pending') {
                throw new ConflictHttpException('Only pending cash movements can be rejected.');
            }
            $account = $this->lockAccountForEntry($tenant, $actor, $entry);
            if (! $this->canApprove($actor, $account, (string) $entry->amount)) {
                throw new AccessDeniedHttpException('You are not authorized to reject this cash movement.');
            }
            $entry->update(['approval_status' => 'rejected']);
            if ($entry->transfer_group_uuid) {
                CashManagementEntry::where('tenant_id', $tenant->id)->where('transfer_group_uuid', $entry->transfer_group_uuid)->where('approval_status', 'pending')->update(['approval_status' => 'rejected']);
            }
            $this->audit->record('cash.entry_rejected', $actor, $tenant, $entry, ['entry_id' => $entry->uuid]);

            return $entry->fresh();
        });
    }

    public function reverse(Tenant $tenant, User $actor, CashManagementEntry $entry, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($tenant, $actor, $entry, $idempotencyKey): array {
            $entry = CashManagementEntry::where('tenant_id', $tenant->id)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->approval_status !== 'approved' || $entry->reversed_entry_id) {
                throw new ConflictHttpException('Only an unreversed approved cash movement can be reversed.');
            }
            $existingReversal = CashManagementEntry::where('tenant_id', $tenant->id)->where('idempotency_key', 'like', $idempotencyKey.':%')->first();
            if ($existingReversal) {
                return CashManagementEntry::where('tenant_id', $tenant->id)->where('transfer_group_uuid', $existingReversal->transfer_group_uuid)->get()->all();
            }
            $group = $entry->transfer_group_uuid;
            $originals = $group
                ? CashManagementEntry::where('tenant_id', $tenant->id)->where('transfer_group_uuid', $group)->lockForUpdate()->get()
                : collect([$entry]);
            $newGroup = (string) Str::uuid();
            $reversals = [];
            foreach ($originals as $original) {
                $account = $this->lockAccountForEntry($tenant, $actor, $original);
                if ($original->direction === 'credit' && bccomp((string) $original->amount, $this->accountBalance($account), 2) > 0) {
                    throw new UnprocessableEntityHttpException('The cash account has insufficient available cash to reverse this movement.');
                }
                $reversal = CashManagementEntry::create([
                    'tenant_id' => $tenant->id,
                    'store_id' => $original->store_id,
                    'fund_id' => $original->fund_id,
                    'register_id' => $original->register_id,
                    'cashier_shift_id' => $original->cashier_shift_id,
                    'entry_type' => 'reversal',
                    'direction' => $original->direction === 'credit' ? 'debit' : 'credit',
                    'amount' => $original->amount,
                    'category' => 'reversal',
                    'reason' => 'Reversal of '.$original->uuid.': '.($original->reason ?: 'cash movement'),
                    'reference' => $original->reference,
                    'transfer_group_uuid' => $newGroup,
                    'approval_status' => 'approved',
                    'created_by' => $actor->id,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'reversed_entry_id' => $original->id,
                    'idempotency_key' => $idempotencyKey.':'.$original->id,
                ]);
                $original->update(['approval_status' => 'reversed']);
                $reversals[] = $reversal;
            }
            $this->audit->record('cash.entry_reversed', $actor, $tenant, $entry, ['entry_id' => $entry->uuid, 'transfer_group' => $group]);

            return $reversals;
        });
    }

    public function reconcile(Tenant $tenant, User $actor, array $data, string $idempotencyKey): CashReconciliation
    {
        return DB::transaction(function () use ($tenant, $actor, $data, $idempotencyKey): CashReconciliation {
            $existing = CashReconciliation::where('tenant_id', $tenant->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
            $account = $this->lockAccount($tenant, $actor, $data['source_type'], $data['source_id']);
            $expected = $this->accountBalance($account);
            $actual = (string) $data['actual_amount'];
            $variance = bcsub($actual, $expected, 2);
            $reconciliation = CashReconciliation::create([
                'tenant_id' => $tenant->id,
                'store_id' => $account['store_id'],
                'fund_id' => $account['fund_id'],
                'register_id' => $account['register_id'],
                'cashier_shift_id' => $account['shift_id'],
                'expected_amount' => $expected,
                'actual_amount' => $actual,
                'variance' => $variance,
                'status' => bccomp($variance, '0', 2) === 0 ? 'matched' : 'variance',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->audit->record('cash.reconciliation_created', $actor, $tenant, $reconciliation, ['reconciliation_id' => $reconciliation->uuid, 'variance' => $variance]);

            return $reconciliation;
        });
    }

    public function fundBalance(CashFund $fund): string
    {
        $credits = CashManagementEntry::where('fund_id', $fund->id)->where('approval_status', 'approved')->where('direction', 'credit')->sum('amount');
        $debits = CashManagementEntry::where('fund_id', $fund->id)->where('approval_status', 'approved')->where('direction', 'debit')->sum('amount');

        return bcsub(bcadd((string) $fund->opening_balance, (string) $credits, 2), (string) $debits, 2);
    }

    public function registerBalance(CashierShift $shift): string
    {
        $credits = CashMovement::where('cashier_shift_id', $shift->id)->whereIn('movement_type', ['opening_float', 'cash_sale', 'cash_in'])->sum('amount');
        $credits = bcadd((string) $credits, (string) CashManagementEntry::where('register_id', $shift->register_id)->where('cashier_shift_id', $shift->id)->where('approval_status', 'approved')->where('direction', 'credit')->sum('amount'), 2);
        $debits = CashMovement::where('cashier_shift_id', $shift->id)->whereIn('movement_type', ['cash_refund', 'cash_out'])->sum('amount');
        $debits = bcadd((string) $debits, (string) CashManagementEntry::where('register_id', $shift->register_id)->where('cashier_shift_id', $shift->id)->where('approval_status', 'approved')->where('direction', 'debit')->sum('amount'), 2);

        return bcsub((string) $credits, (string) $debits, 2);
    }

    private function store(Tenant $tenant, ?string $uuid): ?Store
    {
        return $uuid ? Store::where('tenant_id', $tenant->id)->where('uuid', $uuid)->where('status', 'active')->firstOrFail() : null;
    }

    private function assertFundAccess(User $actor, ?Store $store, int $tenantId): void
    {
        if (! $store) {
            if ($this->access->role($actor, $tenantId) !== 'business_admin') {
                throw new AccessDeniedHttpException('Only a Business Admin can manage business-wide petty cash.');
            }
            return;
        }
        if (! $this->access->canManage($actor, $store)) {
            throw new AccessDeniedHttpException('You are not authorized to manage this branch cash fund.');
        }
    }

    private function lockAccount(Tenant $tenant, User $actor, string $type, string $uuid): array
    {
        if ($type === 'fund') {
            $fund = CashFund::where('tenant_id', $tenant->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($fund->status !== 'active') {
                throw new ConflictHttpException('This petty cash fund is closed.');
            }
            $this->assertFundAccess($actor, $fund->store, (int) $tenant->id);

            return ['key' => 'fund:'.$fund->id, 'fund' => $fund, 'fund_id' => $fund->id, 'register_id' => null, 'shift_id' => null, 'store_id' => $fund->store_id];
        }
        $register = PosRegister::where('tenant_id', $tenant->id)->where('uuid', $uuid)->where('status', 'active')->firstOrFail();
        $this->assertFundAccess($actor, $register->store, (int) $tenant->id);
        $shift = CashierShift::where('tenant_id', $tenant->id)->where('register_id', $register->id)->where('status', 'open')->lockForUpdate()->first();
        if (! $shift) {
            throw new ConflictHttpException('An open cashier shift is required for register cash transfers.');
        }

        return ['key' => 'register:'.$register->id, 'register' => $register, 'fund_id' => null, 'register_id' => $register->id, 'shift_id' => $shift->id, 'store_id' => $register->store_id, 'shift' => $shift];
    }

    private function lockAccountForEntry(Tenant $tenant, User $actor, CashManagementEntry $entry): array
    {
        return $entry->fund_id
            ? $this->lockAccount($tenant, $actor, 'fund', (string) $entry->fund?->uuid)
            : $this->lockAccount($tenant, $actor, 'register', (string) $entry->register?->uuid);
    }

    private function accountBalance(array $account): string
    {
        return isset($account['fund']) ? $this->fundBalance($account['fund']) : $this->registerBalance($account['shift']);
    }

    private function approvalStatus(User $actor, CashFund $fund, string $amount, int $tenantId): string
    {
        return $this->approvalStatusForAccount($actor, ['fund' => $fund], $amount, $tenantId);
    }

    private function approvalStatusForAccount(User $actor, array $account, string $amount, int $tenantId): string
    {
        $role = $this->access->role($actor, $tenantId);
        if ($role === 'business_admin') {
            return 'approved';
        }
        $limit = isset($account['fund']) ? (string) $account['fund']->approval_limit : '0';

        return bccomp($amount, $limit, 2) <= 0 && bccomp($limit, '0', 2) > 0 ? 'approved' : 'pending';
    }

    private function canApprove(User $actor, array $account, string $amount): bool
    {
        $tenantId = isset($account['fund']) ? (int) $account['fund']->tenant_id : (int) ($account['register']->tenant_id ?? 0);
        $role = $this->access->role($actor, $tenantId);
        if ($role === 'business_admin') {
            return true;
        }
        return $role === 'store_manager' && isset($account['fund']) && bccomp($amount, (string) $account['fund']->approval_limit, 2) <= 0 && bccomp((string) $account['fund']->approval_limit, '0', 2) > 0;
    }
}
