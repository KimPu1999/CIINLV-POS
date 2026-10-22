<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PlatformAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user?->isSuperAdmin()) {
            $accessToken = $user?->currentAccessToken();
            $webUser = Auth::guard('web')->user();

            // Keep this diagnostic deliberately limited to identifiers and authorization
            // state. Never record Authorization, cookies, passwords, or token values.
            Log::warning('Platform administrator authorization denied.', [
                'request_id' => $request->attributes->get('correlation_id'),
                'route' => $request->route()?->getName() ?: $request->path(),
                'method' => $request->method(),
                'authenticated_user_id' => $user?->getAuthIdentifier(),
                'platform_role' => $user?->platform_role,
                'status' => $user?->status,
                'is_super_admin' => $user?->isSuperAdmin() ?? false,
                'auth_guard' => 'sanctum',
                'auth_transport' => $user && $webUser
                    && $webUser->getAuthIdentifier() === $user->getAuthIdentifier()
                    ? 'stateful_web_session'
                    : ($accessToken instanceof PersonalAccessToken ? 'personal_access_token' : 'unresolved'),
            ]);

            throw new AccessDeniedHttpException('Platform administrator access is required.');
        }

        return $next($request);
    }
}
