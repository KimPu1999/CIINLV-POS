<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PurchaseOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_state_machine_and_statistics_are_authorized(): void
    {
        [$tenant, $admin] = $this->business('PO-A', 'po-admin@example.com');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $warehouse = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/warehouses', ['name' => 'Receiving Warehouse', 'code' => 'PO-WH'])->assertCreated();
        $product = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/products', ['name' => 'PO Product', 'variants' => [['sku' => 'PO-SKU', 'selling_price' => 20, 'purchase_cost' => 10]]])->assertCreated();
        $supplier = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/suppliers', ['supplier_code' => 'PO-SUP', 'name' => 'PO Supplier'])->assertCreated();
        $order = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier->json('data.id'), 'destination_location_id' => $warehouse->json('data.id'), 'purchase_order_number' => 'PO-100', 'items' => [['variant_id' => $product->json('data.variants.0.id'), 'ordered_quantity' => 4, 'unit_cost' => 10]]])->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/purchase-orders/statistics')->assertOk()->assertJsonPath('data.draft_orders', 1);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders/'.$order->json('data.id').'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders/'.$order->json('data.id').'/approve')->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders/'.$order->json('data.id').'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
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
