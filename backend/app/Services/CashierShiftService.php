<?php

namespace App\Services;

use App\Events\CashierShiftClosed;
use App\Models\CashierShift;
use App\Models\CashMovement;
use App\Models\PosRegister;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class CashierShiftService
{
    public function __construct(private readonly PosAccessService $access, private readonly AuditLogger $audit) {}

    public function open(Store $store, PosRegister $register, User $cashier, string $openingAmount): CashierShift
    {
        return DB::transaction(function () use ($store, $register, $cashier, $openingAmount): CashierShift {
            $this->assertRegister($store, $register, $cashier);
            if (CashierShift::where('tenant_id', $store->tenant_id)->where('register_id', $register->id)->where('status', 'open')->exists()) {
                throw new ConflictHttpException('This register already has an open cashier shift.');
            }
            if (CashierShift::where('tenant_id', $store->tenant_id)->where('cashier_user_id', $cashier->id)->where('status', 'open')->exists()) {
                throw new ConflictHttpException('This cashier already has an open shift.');
            }

            $shift = CashierShift::create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'register_id' => $register->id,
                'cashier_user_id' => $cashier->id,
                'opening_amount' => $openingAmount,
                'expected_amount' => $openingAmount,
                'status' => 'open',
                'opened_at' => now(),
            ]);
            CashMovement::create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'register_id' => $register->id,
                'cashier_shift_id' => $shift->id,
                'movement_type' => 'opening_float',
                'amount' => $openingAmount,
                'reference_type' => 'cashier_shift',
                'reference_id' => $shift->uuid,
                'created_by' => $cashier->id,
                'idempotency_key' => 'shift:'.$shift->uuid.':opening',
            ]);
            $this->audit->record('pos.shift_opened', $cashier, $store->tenant, $shift, ['store_id' => $store->uuid, 'register_id' => $register->uuid]);

            return $shift->load(['store', 'register', 'cashier']);
        });
    }

    public function close(CashierShift $shift, User $actor, string $actualAmount): CashierShift
    {
        $closed = DB::transaction(function () use ($shift, $actor, $actualAmount): CashierShift {
            $shift = CashierShift::whereKey($shift->id)->lockForUpdate()->with('store', 'register')->firstOrFail();
            if ($shift->status !== 'open') {
                throw new ConflictHttpException('This cashier shift is already closed.');
            }
            if (! $this->access->canManage($actor, $shift->store) && $shift->cashier_user_id !== $actor->id) {
                throw new AccessDeniedHttpException('You cannot close this cashier shift.');
            }
            $expected = $this->expected($shift);
            $shift->update([
                'closing_amount' => $actualAmount,
                'expected_amount' => $expected,
                'actual_amount' => $actualAmount,
                'cash_difference' => bcsub($actualAmount, $expected, 2),
                'status' => 'closed',
                'closed_at' => now(),
            ]);
            $this->audit->record('pos.shift_closed', $actor, $shift->store->tenant, $shift, ['expected_amount' => $expected, 'actual_amount' => $actualAmount, 'cash_difference' => $shift->cash_difference]);

            return $shift->fresh(['store', 'register', 'cashier']);
        });
        event(new CashierShiftClosed($closed->loadMissing(['tenant', 'store'])));

        return $closed;
    }

    public function movement(CashierShift $shift, User $actor, string $type, string $amount, string $reason, string $idempotencyKey): CashMovement
    {
        return DB::transaction(function () use ($shift, $actor, $type, $amount, $reason, $idempotencyKey): CashMovement {
            $shift = CashierShift::whereKey($shift->id)->lockForUpdate()->with('store', 'register')->firstOrFail();
            $existing = CashMovement::where('tenant_id', $shift->tenant_id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
            if ($shift->status !== 'open') {
                throw new ConflictHttpException('Cash movements cannot be added to a closed shift.');
            }
            if (! $this->access->canManage($actor, $shift->store) && $shift->cashier_user_id !== $actor->id) {
                throw new AccessDeniedHttpException('You cannot add cash movements to this shift.');
            }
            if ($type === 'cash_out' && bccomp($amount, $this->expected($shift), 2) > 0) {
                throw new UnprocessableEntityHttpException('Cash out exceeds the expected cash balance.');
            }
            $movement = CashMovement::create(['tenant_id' => $shift->tenant_id, 'store_id' => $shift->store_id, 'register_id' => $shift->register_id, 'cashier_shift_id' => $shift->id, 'movement_type' => $type, 'amount' => $amount, 'reason' => $reason, 'created_by' => $actor->id, 'idempotency_key' => $idempotencyKey]);
            $this->audit->record('pos.cash_movement_created', $actor, $shift->store->tenant, $movement, ['type' => $type, 'amount' => $amount]);

            return $movement;
        });
    }

    public function expected(CashierShift $shift): string
    {
        $opening = (string) $shift->opening_amount;
        $cashIn = (string) CashMovement::where('cashier_shift_id', $shift->id)->whereIn('movement_type', ['cash_sale', 'cash_in'])->sum('amount');
        $cashOut = (string) CashMovement::where('cashier_shift_id', $shift->id)->whereIn('movement_type', ['cash_refund', 'cash_out'])->sum('amount');

        return bcsub(bcadd($opening, $cashIn, 2), $cashOut, 2);
    }

    private function assertRegister(Store $store, PosRegister $register, User $cashier): void
    {
        if ((int) $register->tenant_id !== (int) $store->tenant_id || (int) $register->store_id !== (int) $store->id || $register->status !== 'active' || ! $this->access->canOperateRegister($cashier, $register)) {
            throw new UnprocessableEntityHttpException('The register is not available to this cashier in the selected store.');
        }
    }
}
