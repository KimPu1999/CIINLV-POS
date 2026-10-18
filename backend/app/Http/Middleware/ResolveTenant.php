<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->tenantContext->resolveFor($request, $request->user());

        return $next($request);
    }
}
