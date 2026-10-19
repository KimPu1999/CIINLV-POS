<?php

namespace Tests\Feature;

use App\Models\InventoryLocation;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseFiveInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_barcodes_and_categories_are_tenant_isolated(): void
    {
        [$tenantA, $adminA] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        [$tenantB, $adminB] = $this->business('BUS-B', 'admin-b@example.com', 'business_admin');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $category = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/categories', ['name' => 'Beverages'])->assertCreated();
        $product = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/products', ['category_id' => $category->json('data.id'), 'name' => 'Cola', 'variants' => [['sku' => 'COLA-001', 'barcode' => '4006381333931', 'barcode_type' => 'ean-13', 'selling_price' => 1000, 'purchase_cost' => 700]]])->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/categories?search=beverages')->assertOk()->assertJsonPath('data.0.product_count', 1);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/products/barcode/4006381333931')->assertOk()->assertJsonPath('data.sku', 'COLA-001');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/products', ['name' => 'Duplicate SKU', 'variants' => [['sku' => 'COLA-001', 'selling_price' => 1]]])->assertStatus(409);
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/products/'.$product->json('data.id'))->assertNotFound();
        $child = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/categories', ['name' => 'Soft Drinks', 'parent_id' => $category->json('data.id')])->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->patchJson('/api/v1/categories/'.$category->json('data.id'), ['parent_id' => $child->json('data.id')])->assertUnprocessable();
    }

    public function test_opening_stock_is_transactional_idempotent_and_cashier_cannot_adjust(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $warehouse = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/warehouses', ['name' => 'Main Warehouse', 'code' => 'WH-001'])->assertCreated();
        $product = $this->product($tenant, $admin, 'COFFEE-001');
        $location = InventoryLocation::where('uuid', $warehouse->json('data.id'))->firstOrFail();
        $variant = $product->variants()->firstOrFail();
        $payload = ['location_id' => $location->uuid, 'variant_id' => $variant->uuid, 'quantity' => 10, 'unit_cost' => 5];
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'opening-coffee-1')->postJson('/api/v1/inventory/opening-stock', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'opening-coffee-1')->postJson('/api/v1/inventory/opening-stock', $payload)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 10]);
        $cashier = $this->userWithMembership($tenant, 'cashier@example.com', 'cashier');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'cashier-adjustment')->postJson('/api/v1/inventory/adjustments', ['location_id' => $location->uuid, 'variant_id' => $variant->uuid, 'quantity_delta' => -1, 'reason' => 'unauthorized'])->assertForbidden();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'negative-stock')->postJson('/api/v1/inventory/adjustments', ['location_id' => $location->uuid, 'variant_id' => $variant->uuid, 'quantity_delta' => -11, 'reason' => 'count'])->assertUnprocessable();
    }

    public function test_internal_barcode_generation_is_tenant_scoped_and_does_not_overwrite(): void
    {
        [$tenant, $admin] = $this->business('BUS-BAR', 'barcode-admin@example.com', 'business_admin');
        $product = $this->product($tenant, $admin, 'BAR-001');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $variant = $product->variants()->firstOrFail();

        $generated = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/product-variants/'.$variant->uuid.'/barcode/generate', ['type' => 'internal'])->assertOk();
        $generated->assertJsonPath('data.barcode_type', 'internal');
        $this->assertNotEmpty($generated->json('data.barcode'));
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/product-variants/'.$variant->uuid.'/barcode/generate', ['type' => 'internal'])->assertStatus(409);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/barcodes/statistics')->assertOk()->assertJsonPath('data.with_barcode', 1);
    }

    public function test_product_listing_returns_inventory_summary_and_server_side_filters(): void
    {
        [$tenant, $admin] = $this->business('BUS-LIST', 'products-list@example.com', 'business_admin');
        $product = $this->product($tenant, $admin, 'LIST-001');
        $variant = $product->variants()->firstOrFail();
        $variant->update(['reorder_level' => 15]);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'List Store', 'code' => 'LIST-STORE', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'store', 'store_id' => $store->id, 'name' => 'List Stockroom', 'code' => 'LIST-STOCK', 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => 5, 'version' => 0]);
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/products?search=LIST-001&stock_status=low_stock')
            ->assertOk()->assertJsonPath('data.0.sku', 'LIST-001')->assertJsonPath('data.0.available_stock', 10);
    }

    public function test_purchase_receiving_partial_receipts_and_stock_transfer_update_balances_once(): void
    {
        [$tenant, $admin] = $this->business('BUS-A', 'admin-a@example.com', 'business_admin');
        $headers = ['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'receipt-2'];
        $warehouse = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/warehouses', ['name' => 'Main Warehouse', 'code' => 'WH-001'])->assertCreated();
        $location = InventoryLocation::where('uuid', $warehouse->json('data.id'))->firstOrFail();
        $product = $this->product($tenant, $admin, 'ITEM-001');
        $variant = $product->variants()->firstOrFail();
        $supplier = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/suppliers', ['supplier_code' => 'SUP-001', 'name' => 'Supplier A'])->assertCreated();
        $po = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier->json('data.id'), 'destination_location_id' => $location->uuid, 'purchase_order_number' => 'PO-001', 'items' => [['variant_id' => $variant->uuid, 'ordered_quantity' => 10, 'unit_cost' => 4]]])->assertCreated();
        $poId = $po->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders/'.$poId.'/submit')->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'receipt-1')->postJson('/api/v1/purchase-orders/'.$poId.'/receive', ['items' => [['variant_id' => $variant->uuid, 'quantity_received' => 4]]])->assertCreated();
        $this->assertDatabaseHas('purchase_orders', ['uuid' => $poId, 'status' => 'partially_received']);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'receipt-1')->postJson('/api/v1/purchase-orders/'.$poId.'/receive', ['items' => [['variant_id' => $variant->uuid, 'quantity_received' => 4]]])->assertCreated();
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/purchase-orders/'.$poId.'/receive', ['items' => [['variant_id' => $variant->uuid, 'quantity_received' => 6]]])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 10]);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Branch', 'code' => 'BR-001', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        $storeLocation = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'store', 'store_id' => $store->id, 'name' => 'Branch Stockroom', 'code' => 'BR-001', 'status' => 'active']);
        $transfer = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers', ['source_location_id' => $location->uuid, 'destination_location_id' => $storeLocation->uuid, 'transfer_number' => 'TR-001', 'items' => [['variant_id' => $variant->uuid, 'requested_quantity' => 3]]])->assertCreated();
        $transferId = $transfer->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers/'.$transferId.'/submit')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'reserved_quantity' => 3]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers/'.$transferId.'/approve')->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/stock-transfers/'.$transferId.'/dispatch')->assertOk();
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'on_hand_quantity' => 7, 'reserved_quantity' => 0]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'transfer-receive-1')->postJson('/api/v1/stock-transfers/'.$transferId.'/receive', ['items' => [['variant_id' => $variant->uuid, 'received_quantity' => 3]]])->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'transfer-receive-1')->postJson('/api/v1/stock-transfers/'.$transferId.'/receive', ['items' => [['variant_id' => $variant->uuid, 'received_quantity' => 3]]])->assertOk();
        $this->assertDatabaseCount('stock_transfer_receipts', 1);
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $storeLocation->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 3]);
    }

    private function business(string $code, string $email, string $roleName): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $user];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    private function product(Tenant $tenant, User $admin, string $sku): Product
    {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => $sku, 'slug' => strtolower($sku), 'status' => 'active', 'track_inventory' => true, 'created_by' => $admin->id]);
        $product->variants()->create(['tenant_id' => $tenant->id, 'sku' => $sku, 'selling_price' => 10, 'purchase_cost' => 5, 'status' => 'active']);

        return $product;
    }
}
