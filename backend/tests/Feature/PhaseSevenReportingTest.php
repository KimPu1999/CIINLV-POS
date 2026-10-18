<?php

namespace Tests\Feature;

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
    }

    public function test_business_report_scope_blocks_another_tenant(): void
    {
        [$tenantA, $adminA] = $this->fixture('REPORT-A', 'scope-a');
        [$tenantB, $adminB] = $this->fixture('REPORT-B', 'scope-b');

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/dashboard/overview')->assertForbidden();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/reports/sales/daily')->assertForbidden();
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
