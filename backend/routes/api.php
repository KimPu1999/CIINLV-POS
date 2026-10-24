<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuthenticatedMediaController;
use App\Http\Controllers\Api\V1\BarcodeController;
use App\Http\Controllers\Api\V1\BusinessController;
use App\Http\Controllers\Api\V1\BusinessDashboardController;
use App\Http\Controllers\Api\V1\BusinessInvitationController;
use App\Http\Controllers\Api\V1\BusinessNotificationController;
use App\Http\Controllers\Api\V1\BusinessProfileController;
use App\Http\Controllers\Api\V1\BusinessRegistrationController;
use App\Http\Controllers\Api\V1\BusinessSettingsController;
use App\Http\Controllers\Api\V1\BusinessStoreController;
use App\Http\Controllers\Api\V1\CashierShiftController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CurrentUserStoreController;
use App\Http\Controllers\Api\V1\CustomerManagementController;
use App\Http\Controllers\Api\V1\DashboardOverviewController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InventoryReportController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MarketplaceAdminController;
use App\Http\Controllers\Api\V1\MarketplaceContactController;
use App\Http\Controllers\Api\V1\MarketplaceCustomerAddressController;
use App\Http\Controllers\Api\V1\MarketplaceCustomerAuthController;
use App\Http\Controllers\Api\V1\MarketplaceCustomerCheckoutController;
use App\Http\Controllers\Api\V1\MarketplacePaymentWebhookController;
use App\Http\Controllers\Api\V1\MarketplacePublicController;
use App\Http\Controllers\Api\V1\MarketplaceReviewController;
use App\Http\Controllers\Api\V1\MarketplaceVendorController;
use App\Http\Controllers\Api\V1\MarketplaceVendorFulfillmentController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PlatformBusinessAdminController;
use App\Http\Controllers\Api\V1\PlatformBusinessAuditController;
use App\Http\Controllers\Api\V1\PlatformBusinessControlController;
use App\Http\Controllers\Api\V1\PlatformBusinessController;
use App\Http\Controllers\Api\V1\PlatformBusinessDeletionController;
use App\Http\Controllers\Api\V1\PlatformBusinessInvitationController;
use App\Http\Controllers\Api\V1\PlatformBusinessLifecycleController;
use App\Http\Controllers\Api\V1\PlatformBusinessProductController;
use App\Http\Controllers\Api\V1\PlatformDashboardController;
use App\Http\Controllers\Api\V1\PlatformMonitoringController;
use App\Http\Controllers\Api\V1\PlatformPosMonitoringController;
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
use App\Http\Controllers\Api\V1\PosSalespersonController;
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
use App\Http\Controllers\Api\V1\WarehouseController;
use App\Http\Middleware\SetApiLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);
    Route::get('/health/ready', [HealthController::class, 'ready']);

    Route::prefix('marketplace')->middleware(['throttle:api', SetApiLocale::class])->group(function (): void {
        Route::get('/home', [MarketplacePublicController::class, 'home']);
        Route::get('/stores', [MarketplacePublicController::class, 'stores']);
        Route::get('/stores/{store:slug}', [MarketplacePublicController::class, 'store']);
        Route::get('/products', [MarketplacePublicController::class, 'products']);
        Route::get('/products/{product:slug}/related', [MarketplacePublicController::class, 'related']);
        Route::get('/products/{product:slug}', [MarketplacePublicController::class, 'product']);
        Route::get('/products/{listing:slug}/reviews', [MarketplaceReviewController::class, 'publicIndex']);
        Route::get('/categories', [MarketplacePublicController::class, 'categories']);
        Route::get('/search', [MarketplacePublicController::class, 'search']);
        Route::post('/contact', [MarketplaceContactController::class, 'store']);
        Route::post('/payments/webhook/{provider}', MarketplacePaymentWebhookController::class)->middleware('throttle:payments');
    });

    Route::prefix('marketplace/auth')->middleware(['web', 'throttle:auth', SetApiLocale::class])->group(function (): void {
        Route::post('/register', [MarketplaceCustomerAuthController::class, 'register']);
        Route::post('/login', [MarketplaceCustomerAuthController::class, 'login']);
    });

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
        Route::get('/me/profile-image', [AuthenticatedMediaController::class, 'profileImage'])->name('me.profile-image');
        Route::get('/me/business-logo/{tenant:uuid}', [AuthenticatedMediaController::class, 'businessLogo'])->name('me.business-logo');
        Route::patch('/me', [AuthController::class, 'updateProfile']);
        Route::get('/me/stores', [CurrentUserStoreController::class, 'stores']);
        Route::get('/me/permissions', [CurrentUserStoreController::class, 'permissions']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/change-password', [PasswordController::class, 'change']);

        Route::prefix('platform')->middleware('platform.admin')->group(function (): void {
            Route::get('/dashboard', PlatformDashboardController::class);
            Route::get('/monitoring', PlatformMonitoringController::class);
            Route::prefix('pos-monitoring')->group(function (): void {
                Route::get('/overview', [PlatformPosMonitoringController::class, 'overview']);
                Route::get('/transactions', [PlatformPosMonitoringController::class, 'transactions']);
                Route::get('/transactions/{sale}', [PlatformPosMonitoringController::class, 'show']);
                Route::get('/refunds', [PlatformPosMonitoringController::class, 'refunds']);
                Route::get('/payments', [PlatformPosMonitoringController::class, 'payments']);
                Route::get('/export', [PlatformPosMonitoringController::class, 'export'])->middleware('throttle:exports');
            });
            Route::prefix('business-products')->group(function (): void {
                Route::get('/export', [PlatformBusinessProductController::class, 'export'])->middleware('throttle:exports');
                Route::get('/branches', [PlatformBusinessProductController::class, 'branches']);
                Route::get('/', [PlatformBusinessProductController::class, 'index']);
                Route::get('/{product}', [PlatformBusinessProductController::class, 'show']);
            });

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
            Route::get('/businesses/{business}/verification', [PlatformBusinessController::class, 'verification']);
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

            Route::get('/customers', [CustomerManagementController::class, 'platformIndex']);
            Route::get('/customers/{customer}/orders', [CustomerManagementController::class, 'platformOrders']);
            Route::get('/customers/{customer}/activity', [CustomerManagementController::class, 'activity']);
            Route::get('/customers/{customer}', [CustomerManagementController::class, 'platformShow']);
            Route::patch('/customers/{customer}/status', [CustomerManagementController::class, 'updateStatus']);
            Route::patch('/customers/{customer}/email-verification', [CustomerManagementController::class, 'manuallyVerifyEmail']);
            Route::post('/customers/{customer}/password-reset', [CustomerManagementController::class, 'sendPasswordReset'])->middleware('throttle:password-reset');

            Route::apiResource('tenants', PlatformTenantController::class)->only(['index', 'store', 'show', 'update']);
            Route::post('/tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend']);
            Route::post('/tenants/{tenant}/activate', [PlatformTenantController::class, 'activate']);
            Route::get('/tenants/{tenant}/admins', [PlatformBusinessAdminController::class, 'index']);
            Route::post('/tenants/{tenant}/admins', [PlatformBusinessAdminController::class, 'store']);
            Route::patch('/tenants/{tenant}/admins/{user}', [PlatformBusinessAdminController::class, 'update']);
            Route::delete('/tenants/{tenant}/admins/{user}', [PlatformBusinessAdminController::class, 'destroy']);

        });

        Route::prefix('admin/marketplace')->middleware('platform.admin')->group(function (): void {
            Route::get('/dashboard', [MarketplaceAdminController::class, 'dashboard']);
            Route::get('/stores', [MarketplaceAdminController::class, 'stores']);
            Route::get('/stores/{store}/detail', [MarketplaceAdminController::class, 'vendorDetail']);
            Route::patch('/stores/{store}/approval', [MarketplaceAdminController::class, 'approval']);
            Route::patch('/stores/{store}/status', [MarketplaceAdminController::class, 'storeStatus']);
            Route::get('/listings', [MarketplaceAdminController::class, 'listings']);
            Route::get('/listings/{listing}', [MarketplaceAdminController::class, 'listingDetail']);
            Route::patch('/listings/{listing}/moderation', [MarketplaceAdminController::class, 'moderateListing']);
            Route::get('/orders', [MarketplaceAdminController::class, 'orders']);
            Route::get('/shipments', [MarketplaceAdminController::class, 'shipments']);
            Route::get('/cancellation-requests', [MarketplaceAdminController::class, 'cancellationRequests']);
            Route::patch('/cancellation-requests/{cancellation}/decision', [MarketplaceAdminController::class, 'decideCancellation']);
            Route::get('/return-requests', [MarketplaceAdminController::class, 'returnRequests']);
            Route::patch('/return-requests/{return}/decision', [MarketplaceAdminController::class, 'decideReturn']);
            Route::get('/refund-requests', [MarketplaceAdminController::class, 'refundRequests']);
            Route::get('/orders/{order}', [MarketplaceAdminController::class, 'orderDetail']);
            Route::post('/orders/{order}/investigation-notes', [MarketplaceAdminController::class, 'investigationNote']);
            Route::patch('/orders/{order}/hold', [MarketplaceAdminController::class, 'hold']);
            Route::patch('/orders/{order}/status-correction', [MarketplaceAdminController::class, 'correctOrderStatus']);
            Route::get('/orders/{order}/invoice', [MarketplaceAdminController::class, 'invoice']);
            Route::get('/orders/{order}/receipt', [MarketplaceAdminController::class, 'receipt']);
            Route::patch('/reviews/{review}/moderation', [MarketplaceReviewController::class, 'moderate']);
            Route::post('/orders/{order}/cod/collect', [MarketplaceAdminController::class, 'collectCod']);
            Route::patch('/shipments/{shipment}/status-correction', [MarketplaceAdminController::class, 'correctShipmentStatus']);
            Route::get('/reports', [MarketplaceAdminController::class, 'reports']);
            Route::get('/audit-logs', [MarketplaceAdminController::class, 'auditLogs']);
            Route::get('/settings', [MarketplaceAdminController::class, 'settings']);
            Route::patch('/settings', [MarketplaceAdminController::class, 'updateSettings']);
            Route::post('/settings/qr-payment-image', [MarketplaceAdminController::class, 'uploadQrPaymentImage']);
            Route::delete('/settings/qr-payment-image', [MarketplaceAdminController::class, 'removeQrPaymentImage']);
            Route::get('/commission-businesses', [MarketplaceAdminController::class, 'commissionBusinesses']);
            Route::get('/commission-categories', [MarketplaceAdminController::class, 'commissionCategories']);
            Route::get('/commission-rules', [MarketplaceAdminController::class, 'commissionRules']);
            Route::post('/commission-rules', [MarketplaceAdminController::class, 'storeCommissionRule']);
            Route::patch('/commission-rules/{rule}', [MarketplaceAdminController::class, 'updateCommissionRule']);
            Route::delete('/commission-rules/{rule}', [MarketplaceAdminController::class, 'removeCommissionRule']);
            Route::get('/commission-preview', [MarketplaceAdminController::class, 'commissionPreview']);
            Route::get('/commission-reports', [MarketplaceAdminController::class, 'commissionReports']);
            Route::get('/commission-reports/export', [MarketplaceAdminController::class, 'exportCommissionReport']);
            Route::get('/payout-summaries', [MarketplaceAdminController::class, 'payoutSummaries']);
            Route::get('/payouts', [MarketplaceAdminController::class, 'payouts']);
            Route::post('/payouts', [MarketplaceAdminController::class, 'requestPayout']);
            Route::patch('/payouts/{payout}/approve', [MarketplaceAdminController::class, 'approvePayout']);
            Route::patch('/payouts/{payout}/reject', [MarketplaceAdminController::class, 'rejectPayout']);
        });

        Route::prefix('vendor/marketplace')->middleware('tenant.context')->group(function (): void {
            Route::get('/overview', [MarketplaceVendorController::class, 'overview']);
            Route::get('/products', [MarketplaceVendorController::class, 'products']);
            Route::get('/earnings', [MarketplaceVendorController::class, 'earnings']);
            Route::get('/sales-history/export', [MarketplaceVendorController::class, 'salesHistoryExport'])->middleware('throttle:exports');
            Route::get('/sales-history', [MarketplaceVendorController::class, 'salesHistory']);
            Route::get('/delivery-history', [MarketplaceVendorController::class, 'deliveryHistory']);
            Route::get('/sales-history/{order}', [MarketplaceVendorController::class, 'salesHistoryDetail']);
            Route::get('/orders', [MarketplaceVendorController::class, 'orders']);
            Route::get('/orders/{order}', [MarketplaceVendorController::class, 'order']);
            Route::get('/orders/{order}/invoice', [MarketplaceVendorController::class, 'invoice']);
            Route::get('/orders/{order}/receipt', [MarketplaceVendorController::class, 'receipt']);
            Route::post('/orders/{order}/payments/cod/collect', [MarketplaceVendorController::class, 'collectCod']);
            Route::post('/orders/{order}/shipments', [MarketplaceVendorFulfillmentController::class, 'createShipment']);
            Route::patch('/shipments/{shipment}/status', [MarketplaceVendorFulfillmentController::class, 'updateShipment']);
            Route::get('/store', [MarketplaceVendorController::class, 'store']);
            Route::get('/reviews', [MarketplaceReviewController::class, 'businessIndex']);
            Route::get('/reviews/{review}', [MarketplaceReviewController::class, 'businessShow']);
            Route::patch('/store', [MarketplaceVendorController::class, 'updateStore']);
            Route::post('/store/media', [MarketplaceVendorController::class, 'uploadStoreMedia']);
            Route::delete('/store/media/{mediaType}', [MarketplaceVendorController::class, 'deleteStoreMedia']);
            Route::get('/listings', [MarketplaceVendorController::class, 'listings']);
            Route::post('/listings', [MarketplaceVendorController::class, 'createListing']);
            Route::patch('/listings/{listing}', [MarketplaceVendorController::class, 'updateListing']);
            Route::post('/listings/{listing}/images', [MarketplaceVendorController::class, 'uploadListingImages']);
        });

        Route::prefix('marketplace/auth')->middleware('customer.account')->group(function (): void {
            Route::get('/me', [MarketplaceCustomerAuthController::class, 'me']);
            Route::patch('/me', [MarketplaceCustomerAuthController::class, 'update']);
            Route::post('/logout', [MarketplaceCustomerAuthController::class, 'logout']);
        });

        Route::prefix('marketplace/me/addresses')->middleware('customer.account')->group(function (): void {
            Route::get('/', [MarketplaceCustomerAddressController::class, 'index']);
            Route::post('/', [MarketplaceCustomerAddressController::class, 'store']);
            Route::patch('/{address}', [MarketplaceCustomerAddressController::class, 'update']);
            Route::delete('/{address}', [MarketplaceCustomerAddressController::class, 'destroy']);
        });

        Route::prefix('marketplace')->middleware('customer.account')->group(function (): void {
            Route::get('/reviews/eligible', [MarketplaceReviewController::class, 'eligible']);
            Route::get('/reviews', [MarketplaceReviewController::class, 'customerIndex']);
            Route::post('/reviews', [MarketplaceReviewController::class, 'store'])->middleware('throttle:api');
            Route::post('/reviews/{review}/reports', [MarketplaceReviewController::class, 'report'])->middleware('throttle:api');
            Route::get('/cart', [MarketplaceCustomerCheckoutController::class, 'cart']);
            Route::post('/cart/items', [MarketplaceCustomerCheckoutController::class, 'addCartItem'])->middleware('throttle:api');
            Route::patch('/cart/items/{item}', [MarketplaceCustomerCheckoutController::class, 'updateCartItem'])->middleware('throttle:api');
            Route::delete('/cart/items/{item}', [MarketplaceCustomerCheckoutController::class, 'removeCartItem'])->middleware('throttle:api');
            Route::delete('/cart', [MarketplaceCustomerCheckoutController::class, 'clearCart'])->middleware('throttle:api');
            Route::post('/checkout/quote', [MarketplaceCustomerCheckoutController::class, 'quote'])->middleware('throttle:api');
            Route::post('/checkout', [MarketplaceCustomerCheckoutController::class, 'checkout'])->middleware('throttle:api');
            Route::get('/orders', [MarketplaceCustomerCheckoutController::class, 'orders']);
            Route::get('/orders/{order}/invoice', [MarketplaceCustomerCheckoutController::class, 'invoice']);
            Route::get('/orders/{order}/receipt', [MarketplaceCustomerCheckoutController::class, 'receipt']);
            Route::get('/orders/{order}/tracking', [MarketplaceCustomerCheckoutController::class, 'tracking']);
            Route::post('/orders/{order}/cancellation-requests', [MarketplaceCustomerCheckoutController::class, 'requestCancellation'])->middleware('throttle:api');
            Route::post('/orders/{order}/return-requests', [MarketplaceCustomerCheckoutController::class, 'requestReturn'])->middleware('throttle:api');
            Route::get('/orders/{order}', [MarketplaceCustomerCheckoutController::class, 'order']);
        });

        Route::prefix('business')->middleware('tenant.context')->group(function (): void {
            Route::get('/profile', [BusinessProfileController::class, 'show']);
            Route::patch('/profile', [BusinessProfileController::class, 'update']);
            Route::get('/settings/logo', [BusinessProfileController::class, 'logo'])->name('business.settings.logo');
            Route::post('/settings/logo', [BusinessProfileController::class, 'uploadLogo']);
            Route::delete('/settings/logo', [BusinessProfileController::class, 'removeLogo']);
            Route::post('/verification/document', [BusinessProfileController::class, 'uploadDocument']);
            Route::get('/verification/document/{document}', [BusinessProfileController::class, 'document'])->name('business.verification.document');
            Route::delete('/verification/document/{document}', [BusinessProfileController::class, 'removeDocument']);
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
            Route::get('/notifications/unread-count', [BusinessNotificationController::class, 'unreadCount']);
            Route::get('/notifications', [BusinessNotificationController::class, 'index']);
            Route::patch('/notifications/{notification}/read', [BusinessNotificationController::class, 'markRead']);
            Route::post('/notifications/read-all', [BusinessNotificationController::class, 'markAllRead']);
            Route::get('/dashboard', BusinessDashboardController::class);
            Route::get('/members', [BusinessController::class, 'members']);
            Route::get('/customers', [CustomerManagementController::class, 'businessIndex']);
            Route::get('/customers/{customer}/orders', [CustomerManagementController::class, 'businessOrders']);
            Route::get('/customers/{customer}', [CustomerManagementController::class, 'businessShow']);
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
            Route::post('/products/bulk-status', [ProductController::class, 'bulkStatus']);
            Route::get('/products/export-selected', [ProductController::class, 'exportSelected'])->middleware('throttle:exports');
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
                Route::get('/invoices', [InvoiceController::class, 'index']);
                Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
                Route::apiResource('registers', PosRegisterController::class)->only(['index', 'store', 'show', 'update']);
                Route::get('/salespersons', [PosSalespersonController::class, 'index']);
                Route::get('/registers/{register}/members', [PosRegisterMembershipController::class, 'index']);
                Route::post('/registers/{register}/members', [PosRegisterMembershipController::class, 'store']);
                Route::delete('/registers/{register}/members/{membership}', [PosRegisterMembershipController::class, 'destroy']);
                Route::post('/shifts/open', [CashierShiftController::class, 'open']);
                Route::get('/shifts/current', [CashierShiftController::class, 'current']);
                Route::get('/shifts/register-status', [CashierShiftController::class, 'registerStatus']);
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
