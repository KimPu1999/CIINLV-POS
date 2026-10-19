<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ForceHttps
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('app.force_https', false) || $request->isSecure()) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'HTTPS is required for this endpoint.',
            ], 400);
        }

        return redirect()->secure($request->getRequestUri(), 308);
    }
}
