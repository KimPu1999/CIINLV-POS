<?php

namespace App\Policies;

use App\Models\SaleReturn;
use App\Models\User;
use App\Services\PosAccessService;

class RefundPolicy
{
    public function __construct(private readonly PosAccessService $access) {}

    public function process(User $user, SaleReturn $return): bool
    {
        return $this->access->canManage($user, $return->store);
    }
}
