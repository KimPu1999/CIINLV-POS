<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPosMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_super_admin_can_read_pos_monitoring(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/overview')->assertForbidden();
    }

    public function test_super_admin_can_view_all_tenants_filter_and_audit_read_only_sales(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenantA, $saleA] = $this->saleFixture('Monitor Alpha', 'MON-A', 'completed', 'paid', 100, 0, 100);
        [$tenantB] = $this->saleFixture('Monitor Beta', 'MON-B', 'pending_payment', 'pending', 50, 0, 0);

        $overview = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/overview')->assertOk();
        $overview->assertJsonPath('data.metrics.completed_sales', 1)
            ->assertJsonPath('data.metrics.verified_revenue', '100.00')
            ->assertJsonPath('data.metrics.pending_payments', '50.00')
            ->assertJsonPath('data.metrics.refunds', '0.00')
            ->assertJsonPath('data.metrics.net_sales', '100.00');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/transactions')->assertOk()->assertJsonCount(2, 'data');
        $filtered = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/transactions?business='.$tenantA->uuid)->assertOk();
        $filtered->assertJsonCount(1, 'data')->assertJsonPath('data.0.sale_number', $saleA->sale_number);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/transactions/'.$saleA->uuid)->assertOk()->assertJsonPath('data.id', $saleA->uuid);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.pos_transaction_viewed', 'actor_user_id' => $admin->id]);
    }

    public function test_export_is_authorized_audited_and_does_not_duplicate_split_payments(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [, $sale] = $this->saleFixture('Export Business', 'MON-EXP', 'completed', 'paid', 100, 15, 100);
        Payment::create(['tenant_id' => $sale->tenant_id, 'store_id' => $sale->store_id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 50, 'currency_code' => 'MMK', 'status' => 'verified', 'idempotency_key' => 'monitor-export-cash']);
        Payment::create(['tenant_id' => $sale->tenant_id, 'store_id' => $sale->store_id, 'sale_id' => $sale->id, 'method' => 'qr', 'amount' => 50, 'currency_code' => 'MMK', 'status' => 'verified', 'idempotency_key' => 'monitor-export-qr']);

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/platform/pos-monitoring/export');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($sale->sale_number, $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.pos_monitoring_exported', 'actor_user_id' => $admin->id]);
    }

    public function test_business_and_platform_views_serialize_the_same_authoritative_sale(): void
    {
        $platformAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenant, $sale] = $this->saleFixture('Parity Business', 'MON-PAR', 'completed', 'paid', 125, 10, 125);
        $businessAdmin = User::factory()->create();
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $businessAdmin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        Receipt::create(['tenant_id' => $tenant->id, 'store_id' => $sale->store_id, 'sale_id' => $sale->id, 'receipt_number' => 'RCP-PAR-001', 'format' => '80mm', 'generated_at' => now()]);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Parity Product', 'slug' => 'parity-product', 'status' => 'active', 'track_inventory' => false, 'tax_exempt' => false]);
        $variant = $product->variants()->create(['tenant_id' => $tenant->id, 'sku' => 'PAR-001', 'selling_price' => 125, 'status' => 'active']);
        SaleItem::create(['tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'product_variant_id' => $variant->id, 'product_name_snapshot' => 'Parity Product', 'sku_snapshot' => 'PAR-001', 'quantity' => 1, 'unit_price' => 125, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 125]);
        Payment::create(['tenant_id' => $tenant->id, 'store_id' => $sale->store_id, 'sale_id' => $sale->id, 'method' => 'cash', 'amount' => 125, 'currency_code' => 'MMK', 'status' => 'verified', 'verified_at' => now(), 'idempotency_key' => 'monitor-parity-cash']);

        $business = $this->actingAs($businessAdmin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->getJson('/api/v1/pos/sales?from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d'))->assertOk();
        $platform = $this->actingAs($platformAdmin, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/transactions?business='.$tenant->uuid.'&from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d'))->assertOk();

        $businessSale = $business->json('data.0');
        $platformSale = $platform->json('data.0');
        $this->assertSame($businessSale['id'], $platformSale['id']);
        $this->assertSame($businessSale['grand_total'], $platformSale['grand_total']);
        $this->assertSame($businessSale['payment_status'], $platformSale['payment_status']);
        $this->assertSame($businessSale['receipt_number'], $platformSale['receipt']['number']);
        $this->assertSame($sale->uuid, $platformSale['id']);
        $this->actingAs($platformAdmin, 'sanctum')->getJson('/api/v1/platform/pos-monitoring/transactions/'.$sale->uuid)->assertOk()->assertJsonPath('data.products.0.name', 'Parity Product');
    }

    private function saleFixture(string $name, string $code, string $status, string $paymentStatus, int $total, int $refund, int $paid): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $cashier = User::factory()->create(['name' => $name.' Cashier']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => $name.' Branch', 'code' => $code.'-BR', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $cashier->id]);
        $register = PosRegister::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Register 1', 'code' => $code.'-REG', 'status' => 'active', 'created_by' => $cashier->id]);
        $sale = Sale::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'register_id' => $register->id, 'cashier_user_id' => $cashier->id, 'sale_number' => $code.'-SALE-001', 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => $total, 'paid_total' => $paid, 'balance_due' => $total - $paid, 'refunded_total' => $refund, 'currency_code' => 'MMK', 'status' => $status, 'payment_status' => $paymentStatus, 'completed_at' => $status === 'completed' ? now() : null]);

        return [$tenant, $sale];
    }
}
