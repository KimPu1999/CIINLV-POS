<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenantAnyRole
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->tenantContext->resolveForAnyRole($request, $request->user());

        return $next($request);
    }
}
