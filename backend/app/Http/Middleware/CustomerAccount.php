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

        // A user may have both a POS/business identity and a customer profile.
        // Customer ownership checks still scope carts/orders to this user; the
        // presence of a business membership or platform role must not force a
        // second login or turn customer endpoints into platform endpoints.
        if (! $user || ! $user->isActive() || ! $profile?->isActive()) {
            throw new AccessDeniedHttpException('A customer account is required for this marketplace endpoint.');
        }

        return $next($request);
    }
}
