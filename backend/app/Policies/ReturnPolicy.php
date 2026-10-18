<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\PosAccessService;

class ReturnPolicy
{
    public function __construct(private readonly PosAccessService $access) {}

    public function create(User $user, Sale $sale): bool
    {
        return $this->access->canOperate($user, $sale->store);
    }

    public function view(User $user, SaleReturn $return): bool
    {
        return $this->access->canAccessStore($user, $return->store);
    }

    public function approve(User $user, SaleReturn $return): bool
    {
        return $this->access->canManage($user, $return->store);
    }
}
