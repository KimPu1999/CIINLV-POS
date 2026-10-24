<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseSevenReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_dashboard_uses_completed_sales_and_historical_cost(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->fixture('REPORT-A', 'report-a');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $cashier, $store, $headers);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $register, 'opening_amount' => 100])->assertCreated();
        $sale = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'report-sale-1')->postJson('/api/v1/pos/sales', ['store_id' => $store->uuid, 'register_id' => $register, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 2]]])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'report-payment-1')->postJson('/api/v1/pos/sales/'.$sale->json('data.id').'/payments', ['method' => 'cash', 'amount' => 20])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$sale->json('data.id').'/complete')->assertOk();
        $variant->update(['purchase_cost' => 99]);

        $response = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/dashboard/overview');
        $response->assertOk()->assertJsonPath('data.total_sales', '20.00')->assertJsonPath('data.total_transactions', 1)->assertJsonPath('data.gross_profit', '10.0000');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/reports/sales/by-cashier')->assertOk()->assertJsonPath('data.rows.0.transaction_count', 1);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/reports/sales/by-payment-method')->assertOk()->assertJsonPath('data.rows.0.method', 'cash')->assertJsonPath('data.rows.0.amount', '20.00');
    }

    public function test_business_report_scope_blocks_another_tenant(): void
    {
        [$tenantA, $adminA] = $this->fixture('REPORT-A', 'scope-a');
        [$tenantB, $adminB] = $this->fixture('REPORT-B', 'scope-b');

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/dashboard/overview')->assertForbidden();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/reports/sales/daily')->assertForbidden();
    }

    public function test_dashboard_low_stock_join_is_qualified_and_tenant_scoped(): void
    {
        [$tenantA, $adminA, , , , $variantA] = $this->fixture('REPORT-A', 'low-stock-a');
        [$tenantB, $adminB, , , , $variantB] = $this->fixture('REPORT-B', 'low-stock-b');
        $variantA->update(['reorder_level' => 15]);
        $variantB->update(['reorder_level' => 15]);
        $balanceA = InventoryBalance::where('tenant_id', $tenantA->id)->where('product_variant_id', $variantA->id)->firstOrFail();
        $balanceB = InventoryBalance::where('tenant_id', $tenantB->id)->where('product_variant_id', $variantB->id)->firstOrFail();

        $response = $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/dashboard/low-stock');

        $response->assertOk()->assertJsonPath('data.data.0.product_variant_id', $balanceA->product_variant_id)->assertJsonMissing(['product_variant_id' => $balanceB->product_variant_id]);
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/dashboard/low-stock?store_id=00000000-0000-0000-0000-000000000000')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_low_stock_alerts_are_deduplicated_and_resolved(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->fixture('REPORT-A', 'alerts');
        $variant->update(['reorder_level' => 5]);
        app(InventoryService::class)->mutate($location, $variant, 'adjustment_out', '-10', $admin, 'test', 'alert-empty', null, 'alert-empty');
        app(StockAlertService::class)->evaluate($location, $variant);
        app(StockAlertService::class)->evaluate($location, $variant);
        $this->assertDatabaseCount('stock_alerts', 1);
        $this->assertDatabaseHas('stock_alerts', ['alert_type' => 'out_of_stock', 'status' => 'open']);

        app(InventoryService::class)->mutate($location, $variant, 'opening_stock', '10', $admin, 'test', 'alert-restock', null, 'alert-restock', '5');
        $this->assertDatabaseHas('stock_alerts', ['status' => 'resolved']);
    }

    public function test_low_stock_alert_api_has_qualified_metrics_and_tenant_isolation(): void
    {
        [$tenantA, $adminA, , , $locationA, $variantA] = $this->fixture('REPORT-A', 'alert-api-a');
        [$tenantB, $adminB, , , $locationB, $variantB] = $this->fixture('REPORT-B', 'alert-api-b');
        $variantA->update(['reorder_level' => 15]);
        $variantB->update(['reorder_level' => 15]);
        app(InventoryService::class)->mutate($locationA, $variantA, 'adjustment_out', '-10', $adminA, 'test', 'api-alert-a', null, 'api-alert-a');
        app(InventoryService::class)->mutate($locationB, $variantB, 'adjustment_out', '-10', $adminB, 'test', 'api-alert-b', null, 'api-alert-b');
        app(StockAlertService::class)->evaluate($locationA, $variantA);
        app(StockAlertService::class)->evaluate($locationB, $variantB);

        $response = $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/alerts');
        $response->assertOk()->assertJsonPath('data.data.0.variant.id', $variantA->uuid)->assertJsonMissing(['id' => $variantB->uuid]);
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/alerts/statistics')->assertOk()->assertJsonPath('data.out_of_stock_items', 1)->assertJsonPath('data.unique_variants', 1);
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/alerts?location_id='.$locationA->uuid)->assertOk()->assertJsonCount(1, 'data.data');
        $export = $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->get('/api/v1/alerts/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Alert type', $export->streamedContent());
        $this->assertStringContainsString('Coffee alert-api-a', $export->streamedContent());
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/alerts?store_id=00000000-0000-0000-0000-000000000000')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_alert_preferences_require_channels_to_be_an_array_and_allow_empty_arrays(): void
    {
        [$tenant, $admin] = $this->fixture('REPORT-A', 'alert-preferences');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/alerts/preferences', ['channels' => ['database', 'email']])
            ->assertOk()
            ->assertJsonPath('data.channels.0', 'database')
            ->assertJsonPath('data.channels.1', 'email');

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/alerts/preferences', ['channels' => 'database'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['channels']);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->patchJson('/api/v1/alerts/preferences', ['channels' => []])
            ->assertOk()
            ->assertJsonPath('data.channels', []);
    }

    public function test_alert_lifecycle_is_authorized_and_audited(): void
    {
        [$tenant, $admin, , , $location, $variant] = $this->fixture('REPORT-A', 'alert-lifecycle');
        $variant->update(['reorder_level' => 15]);
        app(InventoryService::class)->mutate($location, $variant, 'adjustment_out', '-10', $admin, 'test', 'lifecycle-alert', null, 'lifecycle-alert');
        $alert = app(StockAlertService::class)->evaluate($location, $variant);
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/alerts/'.$alert->uuid.'/acknowledge')->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/alerts/'.$alert->uuid.'/snooze', ['hours' => 24])->assertOk()->assertJsonPath('data.status', 'snoozed');
        $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/alerts/'.$alert->uuid.'/reopen')->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.stock_alert_acknowledged']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.stock_alert_snoozed']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'inventory.stock_alert_reopened']);
    }

    public function test_alert_listing_handles_open_acknowledged_snoozed_expired_and_resolved_states(): void
    {
        [$tenant, $admin, , , $location, $variant] = $this->fixture('REPORT-A', 'alert-states');
        $variant->update(['reorder_level' => 15]);
        app(InventoryService::class)->mutate($location, $variant, 'adjustment_out', '-10', $admin, 'test', 'alert-states', null, 'alert-states');
        $alert = app(StockAlertService::class)->evaluate($location, $variant);
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/alerts/'.$alert->uuid.'/acknowledge')->assertOk()->assertJsonPath('data.status', 'acknowledged');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts?status=acknowledged')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/alerts/'.$alert->uuid.'/snooze', ['hours' => 24])->assertOk()->assertJsonPath('data.status', 'snoozed');
        $alert->refresh();
        $this->assertInstanceOf(Carbon::class, $alert->snoozed_until);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(1, 'data.data');

        $alert->update(['snoozed_until' => now()->subMinute()]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts?status=snoozed')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts/statistics')->assertOk()->assertJsonPath('data.snoozed_alerts', 0);

        $alert->update(['status' => 'resolved', 'snoozed_until' => null, 'resolved_at' => now()]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/alerts?status=resolved')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_alert_endpoints_deny_a_different_tenant_context(): void
    {
        [$tenantA, $adminA] = $this->fixture('REPORT-A', 'alert-auth-a');
        [$tenantB] = $this->fixture('REPORT-B', 'alert-auth-b');

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/alerts')->assertForbidden();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/alerts/statistics')->assertForbidden();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/alerts')->assertOk();
    }

    public function test_weighted_average_valuation_and_private_export_are_authoritative(): void
    {
        [$tenant, $admin] = $this->fixture('REPORT-A', 'exports');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/reports/inventory/valuation')->assertOk()->assertJsonPath('data.costing_method', 'weighted_average');
        $export = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/reports/exports', ['report_type' => 'inventory', 'format' => 'csv'])->assertStatus(202);
        $exportId = $export->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->get('/api/v1/reports/exports/'.$exportId.'/download')->assertOk();
    }

    private function fixture(string $code, string $suffix): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code.'-'.$suffix), 'business_code' => $code.'-'.strtoupper(substr($suffix, 0, 3)), 'status' => 'active', 'subscription_status' => 'active']);
        $admin = $this->userWithMembership($tenant, 'admin-'.$suffix.'@example.com', 'business_admin');
        $cashier = $this->userWithMembership($tenant, 'cashier-'.$suffix.'@example.com', 'cashier');
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store '.$suffix, 'code' => 'STORE-'.strtoupper(substr($suffix, 0, 3)), 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $cashier->id, 'role' => 'cashier', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'store', 'store_id' => $store->id, 'name' => 'Stockroom '.$suffix, 'code' => 'STOCK-'.strtoupper(substr($suffix, 0, 3)), 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Coffee '.$suffix, 'slug' => strtolower('coffee-'.$suffix), 'status' => 'active', 'track_inventory' => true, 'created_by' => $admin->id]);
        $variant = $product->variants()->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-'.strtoupper(substr($suffix, 0, 3)), 'selling_price' => 10, 'purchase_cost' => 5, 'status' => 'active']);
        app(InventoryService::class)->mutate($location, $variant, 'opening_stock', '10', $admin, 'test', 'opening-'.$suffix, null, 'test-opening', '5');

        return [$tenant, $admin, $cashier, $store, $location, $variant];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    private function register(User $admin, User $cashier, Store $store, array $headers): string
    {
        $register = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers', ['store_id' => $store->uuid, 'name' => 'Register 1', 'code' => 'REG-001'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers/'.$register->json('data.id').'/members', ['user_id' => $cashier->id])->assertCreated();

        return $register->json('data.id');
    }
}
