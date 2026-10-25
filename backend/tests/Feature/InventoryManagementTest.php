<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\InventoryLocation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_statistics_are_tenant_scoped_and_use_real_ledger_values(): void
    {
        [$tenantA, $adminA] = $this->business('INV-A', 'inventory-a@example.com');
        [$tenantB, $adminB] = $this->business('INV-B', 'inventory-b@example.com');
        $warehouse = $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->postJson('/api/v1/warehouses', ['name' => 'A Warehouse', 'code' => 'A-WH'])->assertCreated();

        $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->getJson('/api/v1/inventory/statistics')->assertOk()->assertJsonPath('data.total_stock_units', '0')->assertJsonPath('data.locations', 1);
        $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->getJson('/api/v1/inventory/statistics')->assertOk()->assertJsonPath('data.locations', 0);
        $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->getJson('/api/v1/inventory/locations/'.$warehouse->json('data.id'))->assertNotFound();
    }

    public function test_inventory_statistics_require_an_active_business_manager(): void
    {
        [$tenant] = $this->business('INV-AUTH', 'inventory-auth@example.com');
        $outsider = User::factory()->create(['email' => 'inventory-outsider@example.com']);
        $this->actingAs($outsider, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->getJson('/api/v1/inventory/statistics')->assertForbidden();
    }

    public function test_inventory_catalog_filters_mutations_history_and_archive_are_tenant_scoped(): void
    {
        [$tenantA, $adminA] = $this->business('INV-FLOW-A', 'inventory-flow-a@example.com');
        [$tenantB, $adminB] = $this->business('INV-FLOW-B', 'inventory-flow-b@example.com');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $headersB = ['X-Tenant-Id' => $tenantB->uuid];

        $warehouse = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/warehouses', ['name' => 'Flow Warehouse', 'code' => 'FLOW-WH'])->assertCreated();
        $location = InventoryLocation::where('uuid', $warehouse->json('data.id'))->firstOrFail();
        $product = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/products', ['name' => 'Inventory Flow Product', 'brand' => 'Flow Brand', 'variants' => [['sku' => 'FLOW-001', 'selling_price' => 100, 'purchase_cost' => 60, 'reorder_level' => 2]]])->assertCreated();
        $variantId = $product->json('data.variants.0.id');

        $opening = ['location_id' => $location->uuid, 'variant_id' => $variantId, 'quantity' => 10, 'unit_cost' => 60, 'reason' => 'Initial receipt'];
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'flow-opening-1')->postJson('/api/v1/inventory/opening-stock', $opening)->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'flow-opening-1')->postJson('/api/v1/inventory/opening-stock', $opening)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 1);

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/inventory/filters')->assertOk()->assertJsonFragment(['Flow Brand']);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/inventory?brand=Flow%20Brand&per_page=250')->assertOk()->assertJsonPath('data.data.0.variant.sku', 'FLOW-001')->assertJsonPath('data.per_page', 250);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/inventory?stock_status=low_stock')->assertOk()->assertJsonCount(0, 'data.data');

        $counted = ['location_id' => $location->uuid, 'variant_id' => $variantId, 'quantity' => 7, 'reason' => 'Cycle count', 'reference_number' => 'COUNT-001'];
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'flow-count-1')->postJson('/api/v1/inventory/quantity', $counted)->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'flow-count-1')->postJson('/api/v1/inventory/quantity', $counted)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'on_hand_quantity' => 7]);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/inventory/movements?variant_id='.$variantId.'&location_id='.$location->uuid)->assertOk()->assertJsonPath('data.data.0.quantity_after', '7.000');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'flow-threshold-1')->postJson('/api/v1/inventory/thresholds', ['variant_ids' => [$variantId], 'reorder_level' => 5, 'reason' => 'Reorder policy'])->assertOk()->assertJsonPath('data.updated', 1);
        $csv = UploadedFile::fake()->createWithContent('thresholds.csv', "sku,reorder_level\nFLOW-001,6\n");
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->call('POST', '/api/v1/inventory/import', ['preview' => '1'], [], ['file' => $csv])->assertOk()->assertJsonPath('data.valid_count', 1);

        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->getJson('/api/v1/inventory?search=Inventory%20Flow%20Product')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->postJson('/api/v1/inventory/quantity', $counted)->assertNotFound();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->deleteJson('/api/v1/products/'.$product->json('data.id'))->assertOk();
        $this->assertDatabaseHas('products', ['uuid' => $product->json('data.id'), 'status' => 'archived']);
    }

    private function business(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $user];
    }
}
