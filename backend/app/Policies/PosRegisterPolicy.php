<?php

namespace App\Policies;

use App\Models\PosRegister;
use App\Models\User;
use App\Services\PosAccessService;

class PosRegisterPolicy
{
    public function __construct(private readonly PosAccessService $access) {}

    public function view(User $user, PosRegister $register): bool
    {
        return $this->access->canAccessStore($user, $register->store);
    }

    public function manage(User $user, PosRegister $register): bool
    {
        return $this->access->canManage($user, $register->store);
    }

    public function operate(User $user, PosRegister $register): bool
    {
        return $this->access->canOperateRegister($user, $register);
    }
}
