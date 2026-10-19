<?php

namespace App\Services;

use App\Models\DashboardLayout;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StoreDashboardService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function widgets(Store $store, User $user): array
    {
        $isAdmin = $user->memberships()->where('tenant_id', $store->tenant_id)->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))->exists();

        return $store->dashboardWidgets()->where('is_enabled', true)->orderBy('position')->get()->filter(function ($widget) use ($user, $store, $isAdmin) {
            if ($isAdmin) {
                return true;
            }

            return in_array($widget->widget_key, $this->authorizedWidgetKeys($user, $store), true);
        })->values()->all();
    }

    public function layout(Store $store, ?User $user = null): DashboardLayout
    {
        return $store->dashboardLayouts()->where('is_default', true)->firstOrFail();
    }

    public function updateLayout(Store $store, array $data, User $actor): DashboardLayout
    {
        $layout = $store->dashboardLayouts()->where('is_default', true)->firstOrFail();
        $layout->update(['layout' => $data['layout'], 'name' => $data['name'] ?? $layout->name]);
        $this->auditLogger->record('business.dashboard_layout_updated', $actor, $store->tenant, $layout, ['store_id' => $store->uuid]);

        return $layout->fresh();
    }

    public function updateWidgets(Store $store, array $data, User $actor): array
    {
        return DB::transaction(function () use ($store, $data, $actor): array {
            foreach ($data['widgets'] as $widget) {
                $store->dashboardWidgets()->where('widget_key', $widget['widget_key'])->update([
                    'title' => $widget['title'] ?? config('business.dashboard_widget_keys.'.$widget['widget_key']),
                    'position' => $widget['position'],
                    'width' => $widget['width'] ?? 1,
                    'height' => $widget['height'] ?? 1,
                    'is_enabled' => $widget['is_enabled'] ?? true,
                    'settings' => $widget['settings'] ?? [],
                ]);
            }
            $this->auditLogger->record('business.dashboard_widgets_updated', $actor, $store->tenant, $store, ['widget_count' => count($data['widgets'])]);

            return $store->dashboardWidgets()->orderBy('position')->get()->all();
        });
    }

    public function authorizedWidgetKeys(User $user, Store $store): array
    {
        $membership = $store->storeMemberships()->where('user_id', $user->getKey())->where('status', 'active')->first();

        return match ($membership?->role) {
            'cashier' => ['sales_overview', 'recent_transactions'],
            'store_manager' => ['sales_overview', 'staff_overview', 'daily_revenue', 'weekly_revenue', 'branch_performance', 'recent_transactions'],
            'inventory_manager' => ['total_products', 'stock_summary', 'low_stock', 'supplier_overview'],
            default => [],
        };
    }
}
