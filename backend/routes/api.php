<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessDashboardController;
use App\Http\Controllers\Api\V1\BusinessInvitationController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\BusinessSettingsController;
use App\Http\Controllers\Api\V1\BusinessStoreController;
use App\Http\Controllers\Api\V1\CurrentUserStoreController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PlatformBusinessAdminController;
use App\Http\Controllers\Api\V1\PlatformBusinessAuditController;
use App\Http\Controllers\Api\V1\PlatformBusinessController;
use App\Http\Controllers\Api\V1\PlatformBusinessInvitationController;
use App\Http\Controllers\Api\V1\PlatformDashboardController;
use App\Http\Controllers\Api\V1\PlatformTenantController;
use App\Http\Controllers\Api\V1\StoreDashboardController;
use App\Http\Controllers\Api\V1\StoreDashboardLayoutController;
use App\Http\Controllers\Api\V1\StoreMembershipController;
use App\Http\Controllers\Api\V1\StoreSettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::middleware(['web', 'throttle:auth'])->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    Route::middleware('throttle:password-reset')->group(function (): void {
        Route::post('/auth/forgot-password', [PasswordController::class, 'forgot']);
        Route::post('/auth/reset-password', [PasswordController::class, 'reset']);
        Route::post('/auth/invitations/accept', [BusinessInvitationController::class, 'accept']);
    });

    Route::middleware(['auth:sanctum', 'active.account'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::get('/me/stores', [CurrentUserStoreController::class, 'stores']);
        Route::get('/me/permissions', [CurrentUserStoreController::class, 'permissions']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [PasswordController::class, 'change']);

        Route::prefix('platform')->middleware('platform.admin')->group(function (): void {
            Route::get('/dashboard', PlatformDashboardController::class);

            Route::get('/businesses', [PlatformBusinessController::class, 'index']);
            Route::post('/businesses', [PlatformBusinessController::class, 'store']);
            Route::get('/businesses/{business}', [PlatformBusinessController::class, 'show']);
            Route::patch('/businesses/{business}', [PlatformBusinessController::class, 'update']);
            Route::post('/businesses/{business}/suspend', [PlatformBusinessController::class, 'suspend']);
            Route::post('/businesses/{business}/activate', [PlatformBusinessController::class, 'activate']);
            Route::get('/businesses/{business}/admins', [PlatformBusinessAdminController::class, 'businessIndex']);
            Route::post('/businesses/{business}/admins', [PlatformBusinessAdminController::class, 'businessStore']);
            Route::patch('/businesses/{business}/admins/{user}', [PlatformBusinessAdminController::class, 'businessUpdate']);
            Route::delete('/businesses/{business}/admins/{user}', [PlatformBusinessAdminController::class, 'businessDestroy']);
            Route::post('/businesses/{business}/admins/{user}/resend-invitation', [PlatformBusinessInvitationController::class, 'resend']);
            Route::get('/businesses/{business}/audit-logs', [PlatformBusinessAuditController::class, 'index']);

            Route::apiResource('tenants', PlatformTenantController::class)->only(['index', 'store', 'show', 'update']);
            Route::post('/tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
            Route::post('/tenants/{tenant}/activate', [PlatformTenantController::class, 'activate']);
            Route::get('/tenants/{tenant}/admins', [PlatformBusinessAdminController::class, 'index']);
            Route::post('/tenants/{tenant}/admins', [PlatformBusinessAdminController::class, 'store']);
            Route::patch('/tenants/{tenant}/admins/{user}', [PlatformBusinessAdminController::class, 'update']);
            Route::delete('/tenants/{tenant}/admins/{user}', [PlatformBusinessAdminController::class, 'destroy']);
        });

        Route::prefix('business')->middleware('tenant.context')->group(function (): void {
            Route::get('/profile', [BusinessProfileController::class, 'show']);
            Route::patch('/profile', [BusinessProfileController::class, 'update']);
            Route::get('/settings', [BusinessSettingsController::class, 'show']);
            Route::patch('/settings', [BusinessSettingsController::class, 'update']);
            Route::get('/dashboard', BusinessDashboardController::class);
            Route::get('/members', [BusinessController::class, 'members']);
        });

        Route::prefix('business')->middleware('tenant.context.any')->group(function (): void {
            Route::get('/stores', [BusinessStoreController::class, 'index']);
            Route::post('/stores', [BusinessStoreController::class, 'store']);
            Route::get('/stores/{store}', [BusinessStoreController::class, 'show']);
            Route::patch('/stores/{store}', [BusinessStoreController::class, 'update']);
            Route::post('/stores/{store}/activate', [BusinessStoreController::class, 'activate']);
            Route::post('/stores/{store}/suspend', [BusinessStoreController::class, 'suspend']);
            Route::get('/stores/{store}/settings', [StoreSettingsController::class, 'show']);
            Route::patch('/stores/{store}/settings', [StoreSettingsController::class, 'update']);
            Route::get('/stores/{store}/members', [StoreMembershipController::class, 'index']);
            Route::post('/stores/{store}/members', [StoreMembershipController::class, 'store']);
            Route::patch('/stores/{store}/members/{membership}', [StoreMembershipController::class, 'update']);
            Route::delete('/stores/{store}/members/{membership}', [StoreMembershipController::class, 'destroy']);
            Route::get('/stores/{store}/dashboard', [StoreDashboardController::class, 'show']);
            Route::get('/stores/{store}/dashboard/layout', [StoreDashboardLayoutController::class, 'show']);
            Route::put('/stores/{store}/dashboard/layout', [StoreDashboardLayoutController::class, 'update']);
            Route::get('/stores/{store}/dashboard/widgets', [StoreDashboardController::class, 'widgets']);
            Route::put('/stores/{store}/dashboard/widgets', [StoreDashboardLayoutController::class, 'updateWidgets']);
        });
    });
});
