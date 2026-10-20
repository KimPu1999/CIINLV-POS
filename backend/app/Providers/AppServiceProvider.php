<?php

namespace App\Providers;

use App\Models\InventoryLocation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceStore;
use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Contracts\TranslationProvider;
use App\Policies\InventoryPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\MarketplaceListingPolicy;
use App\Policies\MarketplaceStorePolicy;
use App\Policies\PosRegisterPolicy;
use App\Policies\ProductPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\RefundPolicy;
use App\Policies\ReturnPolicy;
use App\Policies\SalePolicy;
use App\Policies\StockAdjustmentPolicy;
use App\Policies\StockTransferPolicy;
use App\Policies\StoreMembershipPolicy;
use App\Policies\StorePolicy;
use App\Policies\TenantPolicy;
use App\Services\TenantContext;
use App\Services\TenantContextService;
use App\Services\ConfiguredTranslationProvider;
use App\Services\TranslationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContextService::class);
        $this->app->singleton(TenantContext::class, fn ($app) => $app->make(TenantContextService::class));
        $this->app->singleton(TranslationProvider::class, ConfiguredTranslationProvider::class);
        $this->app->singleton(TranslationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(MarketplaceStore::class, MarketplaceStorePolicy::class);
        Gate::policy(MarketplaceListing::class, MarketplaceListingPolicy::class);
        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(StoreMembership::class, StoreMembershipPolicy::class);
        Gate::policy(InventoryLocation::class, InventoryPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(PosRegister::class, PosRegisterPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(SaleReturn::class, ReturnPolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);
        Gate::policy(PurchaseOrder::class, PurchaseOrderPolicy::class);
        Gate::policy(StockTransfer::class, StockTransferPolicy::class);
        Gate::policy(StockAdjustment::class, StockAdjustmentPolicy::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute(3)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('verification-resend', function (Request $request): Limit {
            return Limit::perMinutes(10, 3)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) env('API_RATE_LIMIT_PER_MINUTE', 600))->by((string) $key);
        });

        RateLimiter::for('exports', function (Request $request): Limit {
            $key = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) env('EXPORT_RATE_LIMIT_PER_MINUTE', 10))->by((string) $key);
        });
    }
}
