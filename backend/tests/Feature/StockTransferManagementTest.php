<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StockTransferManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_lifecycle_moves_stock_once_and_reports_statuses(): void
    {
        [$tenant, $admin] = $this->business('TR-A', 'transfer-admin@example.com');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $source = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/warehouses', ['name' => 'Source', 'code' => 'SRC'])->assertCreated();
        $destination = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/warehouses', ['name' => 'Destination', 'code' => 'DST'])->assertCreated();
        $product = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/products', ['name' => 'Transfer Product', 'variants' => [['sku' => 'TR-SKU', 'selling_price' => 20, 'purchase_cost' => 10, 'barcode' => 'TR-001']]])->assertCreated();
        $variant = $product->json('data.variants.0.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'transfer-opening')->postJson('/api/v1/inventory/opening-stock', ['location_id' => $source->json('data.id'), 'variant_id' => $variant, 'quantity' => 10, 'unit_cost' => 10, 'reason' => 'Opening'])->assertCreated();
        $transfer = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers', ['source_location_id' => $source->json('data.id'), 'destination_location_id' => $destination->json('data.id'), 'transfer_number' => 'TR-001', 'items' => [['variant_id' => $variant, 'requested_quantity' => 4]]])->assertCreated();
        $id = $transfer->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers/'.$id.'/submit')->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers/'.$id.'/approve')->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers/'.$id.'/dispatch')->assertOk()->assertJsonPath('data.status', 'dispatched');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'transfer-receive')->postJson('/api/v1/stock-transfers/'.$id.'/receive', ['items' => [['variant_id' => $variant, 'received_quantity' => 4, 'damaged_quantity' => 0, 'missing_quantity' => 0]]])->assertOk()->assertJsonPath('data.status', 'received');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/stock-transfers/statistics')->assertOk()->assertJsonPath('data.completed_transfers', 1);
        $sourceLocation = InventoryLocation::where('uuid', $source->json('data.id'))->firstOrFail();
        $destinationLocation = InventoryLocation::where('uuid', $destination->json('data.id'))->firstOrFail();
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $sourceLocation->id, 'on_hand_quantity' => 6]);
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $destinationLocation->id, 'on_hand_quantity' => 4]);
    }

    public function test_cross_tenant_transfer_is_not_visible(): void
    {
        [$tenantA, $adminA] = $this->business('TR-ISO-A', 'transfer-a@example.com');
        [$tenantB, $adminB] = $this->business('TR-ISO-B', 'transfer-b@example.com');
        $warehouseA = $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->postJson('/api/v1/warehouses', ['name' => 'A', 'code' => 'A'])->assertCreated();
        $warehouseB = $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->postJson('/api/v1/warehouses', ['name' => 'B', 'code' => 'B'])->assertCreated();
        $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->getJson('/api/v1/stock-transfers/locations')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotSame($warehouseA->json('data.id'), $warehouseB->json('data.id'));
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
