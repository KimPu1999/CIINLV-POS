<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessDashboardController;
use App\Http\Controllers\Api\V1\BusinessInvitationController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\BusinessRegistrationController;
use App\Http\Controllers\Api\V1\BusinessSettingsController;
use App\Http\Controllers\Api\V1\BusinessStoreController;
use App\Http\Controllers\Api\V1\CashierShiftController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CurrentUserStoreController;
use App\Http\Controllers\Api\V1\DashboardOverviewController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryReportController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PlatformBusinessAdminController;
use App\Http\Controllers\Api\V1\PlatformBusinessAuditController;
use App\Http\Controllers\Api\V1\PlatformBusinessController;
use App\Http\Controllers\Api\V1\PlatformBusinessControlController;
use App\Http\Controllers\Api\V1\PlatformBusinessDeletionController;
use App\Http\Controllers\Api\V1\PlatformBusinessLifecycleController;
use App\Http\Controllers\Api\V1\PlatformBusinessInvitationController;
use App\Http\Controllers\Api\V1\PlatformDashboardController;
use App\Http\Controllers\Api\V1\PlatformSecurityController;
use App\Http\Controllers\Api\V1\PlatformTenantController;
use App\Http\Controllers\Api\V1\PosCheckoutController;
use App\Http\Controllers\Api\V1\PosPaymentController;
use App\Http\Controllers\Api\V1\PosReceiptController;
use App\Http\Controllers\Api\V1\PosRefundController;
use App\Http\Controllers\Api\V1\PosRegisterController;
use App\Http\Controllers\Api\V1\PosRegisterMembershipController;
use App\Http\Controllers\Api\V1\PosReportController;
use App\Http\Controllers\Api\V1\PosReturnController;
use App\Http\Controllers\Api\V1\PosSaleController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProfitReportController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\ReportExportController;
use App\Http\Controllers\Api\V1\SalesReportController;
use App\Http\Controllers\Api\V1\StockAlertController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\StoreDashboardController;
use App\Http\Controllers\Api\V1\StoreDashboardLayoutController;
use App\Http\Controllers\Api\V1\StoreMembershipController;
use App\Http\Controllers\Api\V1\StoreSettingsController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\TranslationController;
use App\Http\Middleware\SetApiLocale;
use App\Http\Controllers\Api\V1\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    Route::middleware(['web', 'throttle:auth'])->group(function (): void {
        Route::post('/auth/login', [AuthController::class, 'login']);
    });

    Route::middleware('throttle:password-reset')->group(function (): void {
        Route::post('/auth/forgot-password', [PasswordController::class, 'forgot']);
        Route::post('/auth/reset-password', [PasswordController::class, 'reset']);
        Route::get('/auth/invitations/{token}', [BusinessInvitationController::class, 'show']);
        Route::post('/auth/invitations/accept', [BusinessInvitationController::class, 'accept']);
    });

    Route::post('/auth/business-registration', [BusinessRegistrationController::class, 'register'])->middleware('throttle:auth');
    Route::get('/auth/email/verify/{id}/{hash}', [BusinessRegistrationController::class, 'verify'])->name('verification.verify');
    Route::post('/auth/email/verification-notification', [BusinessRegistrationController::class, 'resend'])->middleware('throttle:verification-resend');

    Route::middleware(['auth:sanctum', 'active.account', 'throttle:api', SetApiLocale::class])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::get('/me/stores', [CurrentUserStoreController::class, 'stores']);
        Route::get('/me/permissions', [CurrentUserStoreController::class, 'permissions']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [PasswordController::class, 'change']);

        Route::prefix('platform')->middleware('platform.admin')->group(function (): void {
            Route::get('/dashboard', PlatformDashboardController::class);

            Route::get('/security/overview', [PlatformSecurityController::class, 'overview']);
            Route::get('/security/audit-logs', [PlatformSecurityController::class, 'index']);
            Route::get('/security/audit-logs/export', [PlatformSecurityController::class, 'export'])->middleware('throttle:exports');
            Route::get('/security/audit-logs/{auditLog}', [PlatformSecurityController::class, 'show']);
            Route::get('/security/login-history', [PlatformSecurityController::class, 'loginHistory']);
            Route::get('/security/role-changes', [PlatformSecurityController::class, 'roleChanges']);
            Route::get('/security/policies', [PlatformSecurityController::class, 'policies']);
            Route::get('/security/sessions', [PlatformSecurityController::class, 'sessions']);
            Route::post('/security/sessions/revoke', [PlatformSecurityController::class, 'revokeSession']);

            Route::get('/businesses/statistics', [PlatformBusinessController::class, 'statistics']);
            Route::get('/businesses/export', [PlatformBusinessController::class, 'export'])->middleware('throttle:exports');
            Route::get('/businesses', [PlatformBusinessController::class, 'index']);
            Route::post('/businesses', [PlatformBusinessController::class, 'store']);
            Route::get('/businesses/{business}', [PlatformBusinessController::class, 'show']);
            Route::get('/businesses/{business}/verification/{document}', [PlatformBusinessController::class, 'verificationDocument'])->name('platform.businesses.verification');
            Route::get('/businesses/{business}/control-center', [PlatformBusinessControlController::class, 'show']);
            Route::patch('/businesses/{business}', [PlatformBusinessController::class, 'update']);
            Route::post('/businesses/{business}/suspend', [PlatformBusinessController::class, 'suspend']);
            Route::post('/businesses/{business}/activate', [PlatformBusinessController::class, 'activate']);
            Route::post('/businesses/{business}/archive', [PlatformBusinessLifecycleController::class, 'archive']);
            Route::post('/businesses/{business}/restore', [PlatformBusinessLifecycleController::class, 'restore']);
            Route::post('/businesses/{business}/approve', [BusinessRegistrationController::class, 'approve']);
            Route::get('/businesses/{business}/admins', [PlatformBusinessAdminController::class, 'businessIndex']);
            Route::post('/businesses/{business}/admins', [PlatformBusinessAdminController::class, 'businessStore']);
            Route::patch('/businesses/{business}/admins/{user}', [PlatformBusinessAdminController::class, 'businessUpdate']);
            Route::delete('/businesses/{business}/admins/{user}', [PlatformBusinessAdminController::class, 'businessDestroy']);
            Route::post('/businesses/{business}/admins/{user}/resend-invitation', [PlatformBusinessInvitationController::class, 'resend'])->middleware('throttle:verification-resend');
            Route::post('/businesses/{business}/admins/{user}/revoke-invitation', [PlatformBusinessInvitationController::class, 'revoke']);
            Route::get('/businesses/{business}/audit-logs', [PlatformBusinessAuditController::class, 'index']);
            Route::get('/businesses/{business}/deletion-preview', [PlatformBusinessDeletionController::class, 'preview']);
            Route::post('/businesses/deletion-reauthenticate', [PlatformBusinessDeletionController::class, 'reauthenticate']);
            Route::delete('/businesses/{business}', [PlatformBusinessDeletionController::class, 'destroy']);

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
            Route::get('/settings/logo', [BusinessProfileController::class, 'logo'])->name('business.settings.logo');
            Route::post('/settings/logo', [BusinessProfileController::class, 'uploadLogo']);
            Route::delete('/settings/logo', [BusinessProfileController::class, 'removeLogo']);
            Route::post('/verification/document', [BusinessProfileController::class, 'uploadDocument']);
            Route::get('/verification/document/{document}', [BusinessProfileController::class, 'document'])->name('business.verification.document');
            Route::get('/settings', [BusinessSettingsController::class, 'show']);
            Route::patch('/settings', [BusinessSettingsController::class, 'update']);
            Route::get('/settings/receipt', [BusinessSettingsController::class, 'receipt']);
            Route::patch('/settings/receipt', [BusinessSettingsController::class, 'updateReceipt']);
            Route::patch('/settings/pos', [BusinessSettingsController::class, 'pos']);
            Route::patch('/settings/inventory', [BusinessSettingsController::class, 'inventory']);
            Route::patch('/settings/payment-tax', [BusinessSettingsController::class, 'paymentTax']);
            Route::patch('/settings/notifications', [BusinessSettingsController::class, 'notificationConfiguration']);
            Route::patch('/settings/appearance', [BusinessSettingsController::class, 'appearance']);
            Route::patch('/settings/data', [BusinessSettingsController::class, 'dataConfiguration']);
            Route::get('/settings/subscription', [BusinessSettingsController::class, 'subscription']);
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
            Route::post('/stores/{store}/set-main', [BusinessStoreController::class, 'setMain']);
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

        Route::middleware('tenant.context.any')->group(function (): void {
            Route::get('/dashboard/overview', [DashboardOverviewController::class, 'overview']);
            Route::get('/dashboard/sales-summary', [DashboardOverviewController::class, 'salesSummary']);
            Route::get('/dashboard/inventory-summary', [DashboardOverviewController::class, 'inventorySummary']);
            Route::get('/dashboard/low-stock', [DashboardOverviewController::class, 'lowStock']);
            Route::get('/dashboard/recent-sales', [DashboardOverviewController::class, 'recentSales']);
            Route::get('/dashboard/top-products', [DashboardOverviewController::class, 'topProducts']);
            Route::get('/dashboard/sales-trend', [DashboardOverviewController::class, 'salesTrend']);

            Route::get('/reports/sales/daily', [SalesReportController::class, 'daily']);
            Route::get('/reports/sales/weekly', [SalesReportController::class, 'weekly']);
            Route::get('/reports/sales/monthly', [SalesReportController::class, 'monthly']);
            Route::get('/reports/sales/custom', [SalesReportController::class, 'custom']);
            Route::get('/reports/sales/by-payment-method', [SalesReportController::class, 'byPaymentMethod']);
            Route::get('/reports/sales/by-cashier', [SalesReportController::class, 'byCashier']);
            Route::get('/reports/inventory/valuation', [InventoryReportController::class, 'valuationDetail']);
            Route::get('/reports/inventory/by-location', [InventoryReportController::class, 'byLocation']);
            Route::get('/reports/inventory/movements', [InventoryReportController::class, 'movementsDetail']);
            Route::get('/reports/inventory/low-stock', [InventoryReportController::class, 'lowStockDetail']);
            Route::get('/reports/inventory/out-of-stock', [InventoryReportController::class, 'outOfStockDetail']);
            Route::get('/reports/profit/summary', [ProfitReportController::class, 'summary']);
            Route::get('/reports/profit/by-product', [ProfitReportController::class, 'byProduct']);
            Route::get('/reports/profit/by-branch', [ProfitReportController::class, 'byBranch']);
            Route::get('/reports/branches', [ProfitReportController::class, 'byBranch']);
            Route::get('/reports/branches/comparison', [ProfitReportController::class, 'byBranch']);
            Route::get('/reports/branches/{store}', [ProfitReportController::class, 'byBranch']);

            Route::get('/alerts', [StockAlertController::class, 'index']);
            Route::get('/alerts/statistics', [StockAlertController::class, 'statistics']);
            Route::get('/alerts/export', [StockAlertController::class, 'export'])->middleware('throttle:exports');
            Route::get('/alerts/unread-count', [StockAlertController::class, 'unreadCount']);
            Route::post('/alerts/{alert}/acknowledge', [StockAlertController::class, 'acknowledge']);
            Route::post('/alerts/{alert}/snooze', [StockAlertController::class, 'snooze']);
            Route::post('/alerts/{alert}/reopen', [StockAlertController::class, 'reopen']);
            Route::get('/alerts/preferences', [StockAlertController::class, 'preferences']);
            Route::patch('/alerts/preferences', [StockAlertController::class, 'updatePreferences']);
            Route::middleware('throttle:exports')->group(function (): void {
                Route::post('/reports/exports', [ReportExportController::class, 'store']);
                Route::get('/reports/exports', [ReportExportController::class, 'index']);
                Route::get('/reports/exports/{export}', [ReportExportController::class, 'show']);
                Route::get('/reports/exports/{export}/download', [ReportExportController::class, 'download']);
            });

            Route::apiResource('categories', CategoryController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::get('/products/barcode/{barcode}', [BarcodeController::class, 'show']);
            Route::get('/barcodes/statistics', [BarcodeController::class, 'statistics']);
            Route::post('/product-variants/{variant}/barcode/generate', [BarcodeController::class, 'generate']);
            Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::post('/products/{product}/variants', [ProductController::class, 'variant']);
            Route::post('/products/{product}/images', [ProductController::class, 'images']);
            Route::get('/suppliers/statistics', [SupplierController::class, 'statistics']);
            Route::apiResource('suppliers', SupplierController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
            Route::get('/translations', [TranslationController::class, 'index']);
            Route::patch('/translations/{translation}', [TranslationController::class, 'update']);
            Route::get('/warehouses/statistics', [WarehouseController::class, 'statistics']);
            Route::get('/warehouses/{warehouse}/inventory', [WarehouseController::class, 'inventory']);
            Route::get('/warehouses/{warehouse}/movements', [WarehouseController::class, 'movements']);
            Route::apiResource('warehouses', WarehouseController::class)->parameters(['warehouses' => 'warehouse'])->only(['index', 'store', 'show', 'update']);

            Route::get('/inventory', [InventoryController::class, 'index']);
            Route::get('/inventory/statistics', [InventoryController::class, 'statistics']);
            Route::get('/inventory/locations/{location}', [InventoryController::class, 'location']);
            Route::get('/inventory/movements', [InventoryController::class, 'movements']);
            Route::get('/inventory/low-stock', [InventoryController::class, 'lowStock']);
            Route::get('/inventory/out-of-stock', [InventoryController::class, 'outOfStock']);
            Route::post('/inventory/opening-stock', [InventoryController::class, 'opening']);
            Route::post('/inventory/adjustments', [InventoryController::class, 'adjustment']);
            Route::get('/inventory/adjustments', [InventoryController::class, 'adjustments']);

            Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
            Route::get('/purchase-orders/statistics', [PurchaseOrderController::class, 'statistics']);
            Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
            Route::get('/purchase-orders/{order}', [PurchaseOrderController::class, 'show']);
            Route::post('/purchase-orders/{order}/submit', [PurchaseOrderController::class, 'submit']);
            Route::post('/purchase-orders/{order}/approve', [PurchaseOrderController::class, 'approve']);
            Route::post('/purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel']);
            Route::post('/purchase-orders/{order}/receive', [PurchaseOrderController::class, 'receive']);

            Route::get('/stock-transfers', [StockTransferController::class, 'index']);
            Route::get('/stock-transfers/statistics', [StockTransferController::class, 'statistics']);
            Route::get('/stock-transfers/locations', [StockTransferController::class, 'locations']);
            Route::post('/stock-transfers', [StockTransferController::class, 'store']);
            Route::get('/stock-transfers/{transfer}', [StockTransferController::class, 'show']);
            Route::post('/stock-transfers/{transfer}/submit', [StockTransferController::class, 'submit']);
            Route::post('/stock-transfers/{transfer}/approve', [StockTransferController::class, 'approve']);
            Route::post('/stock-transfers/{transfer}/dispatch', [StockTransferController::class, 'dispatch']);
            Route::post('/stock-transfers/{transfer}/receive', [StockTransferController::class, 'receive']);
            Route::post('/stock-transfers/{transfer}/cancel', [StockTransferController::class, 'cancel']);

            Route::get('/reports/inventory', [InventoryReportController::class, 'inventory']);
            Route::get('/reports/stock-movements', [InventoryReportController::class, 'movements']);
            Route::get('/reports/stock-transfers', [InventoryReportController::class, 'transfers']);
            Route::get('/reports/stock-valuation', [InventoryReportController::class, 'valuation']);

            Route::prefix('pos')->group(function (): void {
                Route::apiResource('registers', PosRegisterController::class)->only(['index', 'store', 'show', 'update']);
                Route::get('/registers/{register}/members', [PosRegisterMembershipController::class, 'index']);
                Route::post('/registers/{register}/members', [PosRegisterMembershipController::class, 'store']);
                Route::delete('/registers/{register}/members/{membership}', [PosRegisterMembershipController::class, 'destroy']);
                Route::post('/shifts/open', [CashierShiftController::class, 'open']);
                Route::get('/shifts/current', [CashierShiftController::class, 'current']);
                Route::get('/shifts', [CashierShiftController::class, 'index']);
                Route::post('/shifts/{shift}/close', [CashierShiftController::class, 'close']);
                Route::post('/shifts/{shift}/cash-movements', [CashierShiftController::class, 'movement']);

                Route::post('/checkout/preview', [PosCheckoutController::class, 'preview']);
                Route::get('/sales', [PosSaleController::class, 'index']);
                Route::get('/sales/statistics', [PosSaleController::class, 'statistics']);
                Route::post('/sales', [PosSaleController::class, 'store']);
                Route::get('/sales/{sale}', [PosSaleController::class, 'show']);
                Route::post('/sales/{sale}/hold', [PosSaleController::class, 'hold']);
                Route::post('/sales/{sale}/resume', [PosSaleController::class, 'resume']);
                Route::post('/sales/{sale}/cancel', [PosSaleController::class, 'cancel']);
                Route::post('/sales/{sale}/complete', [PosSaleController::class, 'complete']);
                Route::get('/sales/{sale}/payments', [PosPaymentController::class, 'index']);
                Route::post('/sales/{sale}/payments', [PosPaymentController::class, 'store']);
                Route::get('/sales/{sale}/receipt', [PosReceiptController::class, 'show']);
                Route::get('/sales/{sale}/receipt/pdf', [PosReceiptController::class, 'pdf']);
                Route::post('/sales/{sale}/returns', [PosReturnController::class, 'store']);

                Route::post('/payments/{payment}/verify', [PosPaymentController::class, 'verify']);
                Route::get('/returns', [PosReturnController::class, 'index']);
                Route::get('/returns/statistics', [PosReturnController::class, 'statistics']);
                Route::get('/returns/{return}', [PosReturnController::class, 'show']);
                Route::post('/returns/{return}/approve', [PosReturnController::class, 'approve']);
                Route::post('/returns/{return}/refund', [PosRefundController::class, 'store']);

                Route::get('/reports/daily', [PosReportController::class, 'daily']);
                Route::get('/reports/payments', [PosReportController::class, 'payments']);
                Route::get('/reports/cashiers', [PosReportController::class, 'cashiers']);
                Route::get('/reports/returns', [PosReportController::class, 'returns']);
            });
        });
    });
});
