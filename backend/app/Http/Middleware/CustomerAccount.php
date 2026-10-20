<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CustomerAccount
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        $profile = $user?->customerProfile;

        if (! $user || $user->isSuperAdmin() || ! $profile?->isActive()) {
            throw new AccessDeniedHttpException('A customer account is required for this marketplace endpoint.');
        }

        return $next($request);
    }
}
