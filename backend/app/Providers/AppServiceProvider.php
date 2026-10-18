<?php

namespace App\Providers;

use App\Models\Store;
use App\Models\Tenant;
use App\Policies\StorePolicy;
use App\Policies\TenantPolicy;
use App\Services\TenantContext;
use App\Services\TenantContextService;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Store::class, StorePolicy::class);

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute(3)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
