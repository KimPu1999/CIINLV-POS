<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\InventoryReconciliationService;
use App\Services\InventoryService;
use App\Services\SecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PDO;
use PDOException;
use Tests\TestCase;

class PhaseEightSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_endpoint_checks_the_database_without_changing_the_legacy_health_contract(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'online']);

        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.status', 'ok');
    }

    public function test_api_responses_include_security_headers_and_cors_is_not_wildcarded(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertNotSame(['*'], config('cors.allowed_methods'));
        $this->assertNotSame(['*'], config('cors.allowed_headers'));
    }

    public function test_api_https_enforcement_can_be_enabled_without_redirecting_api_clients(): void
    {
        config(['app.force_https' => true]);

        $this->getJson('/api/v1/health')
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'HTTPS is required for this endpoint.']);
    }

    public function test_security_audit_reports_a_least_privilege_runtime_role_and_rls_status(): void
    {
        $audit = app(SecurityAuditService::class)->run();

        $this->assertFalse($audit['application']['debug']);
        $this->assertFalse($audit['database']['is_superuser']);
        $this->assertFalse($audit['database']['bypass_rls']);
        $this->assertFalse($audit['database']['can_create_roles']);
        $this->assertIsInt($audit['database']['policy_count']);
    }

    public function test_inventory_reconciliation_matches_the_append_only_ledger(): void
    {
        [$tenant, $admin, $location, $variant] = $this->inventoryFixture();
        app(InventoryService::class)->mutate($location, $variant, 'opening_stock', '10', $admin, 'phase8', 'opening-1', null, 'phase8-opening', '5');

        $result = app(InventoryReconciliationService::class)->reconcile($tenant->id);

        $this->assertSame(1, $result['checked']);
        $this->assertSame([], $result['discrepancies']);
    }

    public function test_postgresql_transaction_lock_blocks_a_second_connection(): void
    {
        $config = config('database.connections.pgsql');
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
        $first = new PDO($dsn, $config['username'], $config['password'], $options);
        $second = new PDO($dsn, $config['username'], $config['password'], $options);

        try {
            $first->beginTransaction();
            $first->query('SELECT pg_advisory_xact_lock(881234)');
            $second->beginTransaction();
            $second->exec("SET LOCAL lock_timeout = '200ms'");

            $blocked = false;
            try {
                $second->query('SELECT pg_advisory_xact_lock(881234)');
            } catch (PDOException $exception) {
                $blocked = $exception->getCode() === '55P03' || str_contains($exception->getMessage(), 'lock timeout');
            }

            $this->assertTrue($blocked, 'The second PostgreSQL transaction acquired a lock that should have been held by the first transaction.');
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
            if ($first->inTransaction()) {
                $first->rollBack();
            }
            $first = null;
            $second = null;
        }
    }

    private function inventoryFixture(): array
    {
        $tenant = Tenant::create(['name' => 'Phase 8', 'slug' => 'phase-8', 'business_code' => 'P8', 'status' => 'active']);
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $admin = User::factory()->create(['email' => 'phase8-admin@example.com', 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'warehouse', 'name' => 'Phase 8 Warehouse', 'code' => 'P8-WH', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Phase 8 Product', 'slug' => 'phase-8-product', 'status' => 'active', 'track_inventory' => true, 'created_by' => $admin->id]);
        $variant = $product->variants()->create(['tenant_id' => $tenant->id, 'sku' => 'P8-SKU', 'selling_price' => 10, 'purchase_cost' => 5, 'status' => 'active']);

        return [$tenant, $admin, $location, $variant];
    }
}
