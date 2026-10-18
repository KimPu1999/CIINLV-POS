<?php

namespace App\Services;

use App\Models\DashboardLayout;
use App\Models\DashboardWidget;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class StoreProvisioningService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function create(array $data, int $tenantId, User $actor): Store
    {
        return DB::transaction(function () use ($data, $tenantId, $actor): Store {
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $limit = config('business.branch_limits.'.$tenant->subscription_status, config('business.default_branch_limit'));
            $existing = Store::query()->where('tenant_id', $tenantId)->count();

            if ($limit !== null && $existing >= (int) $limit) {
                throw new UnprocessableEntityHttpException('This business has reached its branch limit.');
            }

            if (Store::query()->where('tenant_id', $tenantId)->where('code', $data['store_code'])->exists()) {
                throw new ConflictHttpException('The store code is already in use in this business.');
            }

            $store = Store::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'code' => $data['store_code'],
                'description' => $data['description'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'] ?? null,
                'country' => $data['country'] ?? null,
                'currency_code' => $data['currency_code'] ?? 'MMK',
                'timezone' => $data['timezone'] ?? 'Asia/Yangon',
                'status' => 'active',
                'created_by' => $actor->getKey(),
            ]);

            StoreSetting::create(['tenant_id' => $tenantId, 'store_id' => $store->getKey()]);
            $this->createDashboardDefaults($store);

            $this->auditLogger->record('business.store_created', $actor, $tenant, $store, [
                'store_code' => $store->code,
            ]);

            return $store->fresh(['setting', 'dashboardWidgets', 'dashboardLayouts']);
        });
    }

    private function createDashboardDefaults(Store $store): void
    {
        $keys = config('business.dashboard_widget_keys', []);
        $layout = [];
        foreach (array_keys($keys) as $position => $key) {
            DashboardWidget::create([
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->getKey(),
                'widget_key' => $key,
                'title' => $keys[$key],
                'position' => $position,
                'width' => 1,
                'height' => 1,
                'is_enabled' => true,
                'settings' => [],
            ]);
            $layout[] = ['widget_key' => $key, 'position' => $position, 'width' => 1, 'height' => 1];
        }

        DashboardLayout::create([
            'tenant_id' => $store->tenant_id,
            'store_id' => $store->getKey(),
            'name' => 'Default layout',
            'layout' => ['version' => 1, 'widgets' => $layout],
            'is_default' => true,
        ]);
    }
}
