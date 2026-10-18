<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use App\Services\PosAccessService;

class SalePolicy
{
    public function __construct(private readonly PosAccessService $access) {}

    public function view(User $user, Sale $sale): bool
    {
        return $this->access->canAccessStore($user, $sale->store);
    }

    public function create(User $user, Store $store): bool
    {
        return $this->access->canOperate($user, $store);
    }

    public function manage(User $user, Sale $sale): bool
    {
        return $this->access->canOperate($user, $sale->store)
            && ($sale->cashier_user_id === $user->id || $this->access->canManage($user, $sale->store));
    }

    public function refund(User $user, Sale $sale): bool
    {
        return $this->access->canManage($user, $sale->store);
    }
}
