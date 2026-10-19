<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\PlatformAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveTenantAnyRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES'));
        $middleware->append(ForceHttps::class);
        $middleware->statefulApi();

        $middleware->api(append: [
            HandleCors::class,
            SecurityHeaders::class,
        ]);
        $middleware->web(append: [SecurityHeaders::class]);

        $middleware->alias([
            'active.account' => ActiveAccount::class,
            'platform.admin' => PlatformAdmin::class,
            'tenant.context' => ResolveTenant::class,
            'tenant.context.any' => ResolveTenantAnyRole::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'The requested API route was not found.',
            ], 404);
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'CSRF token mismatch. Obtain a fresh CSRF cookie and retry the request.',
            ], 419);
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/*') || $exception->getStatusCode() !== 419) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'CSRF token mismatch. Obtain a fresh CSRF cookie and retry the request.',
            ], 419);
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $headers = $exception->getHeaders();
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Try again later.',
                'retry_after' => $retryAfter,
            ], 429, $headers);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') || config('app.debug') || $exception instanceof HttpExceptionInterface
                || $exception instanceof AuthenticationException
                || $exception instanceof AuthorizationException
                || $exception instanceof ValidationException
                || $exception instanceof HttpResponseException) {
                return null;
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected server error occurred.',
            ], 500);
        });
    })->create();
