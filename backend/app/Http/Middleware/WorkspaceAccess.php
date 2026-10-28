<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use App\Services\WorkspaceAccessService;
use Closure;
use Illuminate\Http\Request;

class WorkspaceAccess
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly WorkspaceAccessService $access,
    ) {}

    public function handle(Request $request, Closure $next, ?string $workspace = null): mixed
    {
        $workspace ??= $this->workspaceFor($request);

        if (! $this->access->canAccess($this->tenantContext->tenant(), $request->user(), $workspace)) {
            abort(403, 'You do not have access to this workspace for the selected business.');
        }

        return $next($request);
    }

    private function workspaceFor(Request $request): string
    {
        $path = $request->path();

        if (str_contains($path, '/delivery-management') || str_starts_with($path, 'api/v1/vendor/marketplace')) {
            return 'marketplace';
        }

        return 'business';
    }
}
