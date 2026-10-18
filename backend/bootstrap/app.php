<?php

use App\Http\Middleware\ActiveAccount;
use App\Http\Middleware\PlatformAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\ResolveTenantAnyRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
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
        $middleware->api(append: [
            HandleCors::class,
        ]);

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
    })->create();
