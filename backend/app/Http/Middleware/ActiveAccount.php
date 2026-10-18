<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ActiveAccount
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user || ! $user->isActive()) {
            throw new AccessDeniedHttpException('This account is not active.');
        }

        return $next($request);
    }
}
