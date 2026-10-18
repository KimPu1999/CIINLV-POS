<?php

namespace App\Services;

use App\Models\Store;
use App\Models\Tenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreContextService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function tenant(): Tenant
    {
        return $this->tenantContext->tenant();
    }

    public function store(Store $store): Store
    {
        if ((int) $store->tenant_id !== (int) $this->tenant()->getKey()) {
            throw new NotFoundHttpException('The requested branch was not found.');
        }

        return $store;
    }
}
