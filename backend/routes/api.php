<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PlatformBusinessAdminController;
use App\Http\Controllers\Api\V1\PlatformDashboardController;
use App\Http\Controllers\Api\V1\PlatformTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::middleware(['web', 'throttle:auth'])->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    Route::middleware('throttle:password-reset')->group(function (): void {
        Route::post('/auth/forgot-password', [PasswordController::class, 'forgot']);
        Route::post('/auth/reset-password', [PasswordController::class, 'reset']);
    });

    Route::middleware(['auth:sanctum', 'active.account'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [PasswordController::class, 'change']);

        Route::prefix('platform')->middleware('platform.admin')->group(function (): void {
            Route::get('/dashboard', PlatformDashboardController::class);
            Route::apiResource('tenants', PlatformTenantController::class)->only(['index', 'store', 'show', 'update']);
            Route::post('/tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
            Route::post('/tenants/{tenant}/activate', [PlatformTenantController::class, 'activate']);
            Route::get('/tenants/{tenant}/admins', [PlatformBusinessAdminController::class, 'index']);
            Route::post('/tenants/{tenant}/admins', [PlatformBusinessAdminController::class, 'store']);
            Route::patch('/tenants/{tenant}/admins/{user}', [PlatformBusinessAdminController::class, 'update']);
            Route::delete('/tenants/{tenant}/admins/{user}', [PlatformBusinessAdminController::class, 'destroy']);
        });

        Route::prefix('business')->middleware('tenant.context')->group(function (): void {
            Route::get('/profile', [BusinessController::class, 'profile']);
            Route::get('/dashboard', [BusinessController::class, 'dashboard']);
            Route::get('/members', [BusinessController::class, 'members']);
        });
    });
});
