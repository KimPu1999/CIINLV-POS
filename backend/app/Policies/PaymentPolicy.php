<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Services\PosAccessService;

class PaymentPolicy
{
    public function __construct(private readonly PosAccessService $access) {}

    public function view(User $user, Payment $payment): bool
    {
        return $this->access->canAccessStore($user, $payment->store);
    }

    public function create(User $user, Sale $sale): bool
    {
        return $this->access->canOperate($user, $sale->store);
    }

    public function verify(User $user, Payment $payment): bool
    {
        return $this->access->canManage($user, $payment->store);
    }
}
