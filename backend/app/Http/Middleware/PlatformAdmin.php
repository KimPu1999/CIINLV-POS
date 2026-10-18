<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PlatformAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->user()?->isSuperAdmin()) {
            throw new AccessDeniedHttpException('Platform administrator access is required.');
        }

        return $next($request);
    }
}
