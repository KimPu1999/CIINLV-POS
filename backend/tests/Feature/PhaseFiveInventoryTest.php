<?php

namespace Tests\Feature;

use App\Jobs\ProcessBarcodeBatch;
use App\Models\BarcodeBatch;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\BarcodeBatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        $openingKey = str_repeat('opening-', 14).'1';
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', $openingKey)->postJson('/api/v1/inventory/opening-stock', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', $openingKey)->postJson('/api/v1/inventory/opening-stock', $payload)->assertCreated();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertLessThanOrEqual(100, strlen((string) DB::table('stock_movements')->value('reference_id')));
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

    public function test_barcode_management_assigns_valid_codes_detects_duplicates_and_bulk_skips_existing_values(): void
    {
        [$tenantA, $adminA] = $this->business('BUS-MANAGE-A', 'barcode-management-a@example.com', 'business_admin');
        [$tenantB, $adminB] = $this->business('BUS-MANAGE-B', 'barcode-management-b@example.com', 'business_admin');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $headersB = ['X-Tenant-Id' => $tenantB->uuid];
        $product = $this->product($tenantA, $adminA, 'MANAGE-001');
        $variant = $product->variants()->firstOrFail();
        $second = $product->variants()->create(['tenant_id' => $tenantA->id, 'sku' => 'MANAGE-002', 'selling_price' => 20, 'status' => 'active']);

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->putJson('/api/v1/product-variants/'.$variant->uuid.'/barcode', ['barcode' => '4006381333931', 'type' => 'ean-13'])
            ->assertOk()->assertJsonPath('data.barcode', '4006381333931')->assertJsonPath('data.barcode_type', 'ean-13');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->putJson('/api/v1/product-variants/'.$second->uuid.'/barcode', ['barcode' => '4006381333931', 'type' => 'ean-13'])->assertStatus(409);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->putJson('/api/v1/product-variants/'.$second->uuid.'/barcode', ['barcode' => 'not-an-ean', 'type' => 'ean-13'])->assertUnprocessable();

        $preview = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/barcodes/bulk-generate', ['variant_ids' => [$variant->uuid, $second->uuid], 'type' => 'internal', 'preview' => true])->assertOk();
        $this->assertCount(1, $preview->json('data.preview'));
        $bulk = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/barcodes/bulk-generate', ['variant_ids' => [$variant->uuid, $second->uuid], 'type' => 'internal', 'assignments' => [['variant_id' => $second->uuid, 'barcode' => $preview->json('data.preview.0.barcode')]]])->assertOk();
        $bulk->assertJsonCount(1, 'data.generated')->assertJsonCount(1, 'data.skipped');
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->putJson('/api/v1/product-variants/'.$variant->uuid.'/barcode', ['barcode' => 'OTHER-001', 'type' => 'internal'])->assertNotFound();
    }

    public function test_barcode_batches_generate_once_print_copies_and_preview_csv_assignments(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$tenant, $admin] = $this->business('BUS-BATCH', 'barcode-batch@example.com', 'business_admin');
        $headers = ['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'batch-unique-1'];
        $product = $this->product($tenant, $admin, 'BATCH-001');
        $variant = $product->variants()->firstOrFail();

        $unique = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/barcodes/batches', [
            'generation_mode' => 'unique',
            'barcode_type' => 'internal',
            'variant_ids' => [$variant->uuid],
            'prefix' => 'KIPI-2026-',
        ])->assertAccepted();
        $batch = BarcodeBatch::where('uuid', $unique->json('data.id'))->firstOrFail();
        Queue::assertPushed(ProcessBarcodeBatch::class);
        (new ProcessBarcodeBatch($batch->id))->handle(app(BarcodeBatchService::class));
        $batch->refresh();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->completed_label_count);
        $this->assertStringStartsWith('KIPI-2026-', (string) $variant->refresh()->barcode);

        $copies = $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'batch-copies-1'])->postJson('/api/v1/barcodes/batches', [
            'generation_mode' => 'copies',
            'variant_id' => $variant->uuid,
            'quantity' => 100000,
        ])->assertAccepted();
        $copyBatch = BarcodeBatch::where('uuid', $copies->json('data.id'))->firstOrFail();
        (new ProcessBarcodeBatch($copyBatch->id))->handle(app(BarcodeBatchService::class));
        $labels = $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->getJson('/api/v1/barcodes/batches/'.$copyBatch->uuid.'/labels?offset=0&limit=10')->assertOk();
        $this->assertCount(10, $labels->json('data.labels'));
        $this->assertSame(100000, $labels->json('data.total'));
        $this->assertSame($variant->barcode, $labels->json('data.labels.2.barcode'));
        $this->assertSame(1, $product->variants()->count());

        $csv = UploadedFile::fake()->createWithContent('barcodes.csv', "variant_id,barcode,barcode_type\n{$variant->uuid},CUSTOM-2026-0001,internal\n");
        $preview = $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->call('POST', '/api/v1/barcodes/batches', ['generation_mode' => 'manual_csv', 'overwrite' => '1', 'confirm_overwrite' => '1'], [], ['file' => $csv]);
        $preview->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.requested_label_count', 1);
        $csvBatch = BarcodeBatch::where('uuid', $preview->json('data.id'))->firstOrFail();
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->postJson('/api/v1/barcodes/batches/'.$csvBatch->uuid.'/start', ['allow_partial' => true])->assertAccepted();
        (new ProcessBarcodeBatch($csvBatch->id))->handle(app(BarcodeBatchService::class));
        $this->assertSame('CUSTOM-2026-0001', $variant->refresh()->barcode);
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

    public function test_barcode_catalog_supports_brand_filters_pagination_and_tenant_isolation(): void
    {
        [$tenantA, $adminA] = $this->business('BUS-CATALOG-A', 'barcode-catalog-a@example.com', 'business_admin');
        [$tenantB, $adminB] = $this->business('BUS-CATALOG-B', 'barcode-catalog-b@example.com', 'business_admin');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $headersB = ['X-Tenant-Id' => $tenantB->uuid];

        $branded = Product::create(['tenant_id' => $tenantA->id, 'name' => 'Catalog Product', 'slug' => 'catalog-product', 'brand' => 'Catalog Brand', 'status' => 'active', 'track_inventory' => true, 'created_by' => $adminA->id]);
        $branded->variants()->create(['tenant_id' => $tenantA->id, 'sku' => 'CATALOG-001', 'selling_price' => 10, 'purchase_cost' => 5, 'status' => 'active']);
        $unbranded = Product::create(['tenant_id' => $tenantA->id, 'name' => 'Unbranded Product', 'slug' => 'unbranded-product', 'brand' => null, 'status' => 'active', 'track_inventory' => true, 'created_by' => $adminA->id]);
        $unbranded->variants()->create(['tenant_id' => $tenantA->id, 'sku' => 'CATALOG-002', 'selling_price' => 12, 'purchase_cost' => 6, 'status' => 'active']);
        $this->product($tenantB, $adminB, 'OTHER-CATALOG-001');

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/barcodes/brands')->assertOk()->assertJsonFragment(['Catalog Brand']);
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/barcodes/catalog?brand=Catalog%20Brand&barcode_status=missing&product_status=active&per_page=1&page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.sku', 'CATALOG-001');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/barcodes/catalog?brand=__unbranded__&per_page=25')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku', 'CATALOG-002');
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->getJson('/api/v1/barcodes/catalog?search=Catalog%20Product')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_product_listing_supports_bounded_pagination_filters_and_sorting(): void
    {
        [$tenant, $admin] = $this->business('BUS-PAGE', 'products-page@example.com', 'business_admin');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        for ($index = 1; $index <= 35; $index++) {
            $product = Product::create(['tenant_id' => $tenant->id, 'name' => sprintf('Paged Product %02d', $index), 'slug' => sprintf('paged-product-%02d', $index), 'brand' => $index % 2 === 0 ? 'Even Brand' : 'Odd Brand', 'status' => $index === 35 ? 'inactive' : 'active', 'track_inventory' => true, 'created_by' => $admin->id]);
            $product->variants()->create(['tenant_id' => $tenant->id, 'sku' => sprintf('PAGE-%03d', $index), 'barcode' => sprintf('890000%03d', $index), 'selling_price' => $index, 'purchase_cost' => 1, 'status' => 'active']);
        }

        foreach ([10, 25, 50, 100] as $perPage) {
            $response = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/products?per_page='.$perPage.'&page=1&sort=name&direction=asc')->assertOk();
            $response->assertJsonPath('meta.per_page', $perPage)->assertJsonPath('meta.total', 35)->assertJsonPath('meta.current_page', 1);
            $this->assertLessThanOrEqual($perPage, count($response->json('data')));
        }

        $page = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/products?per_page=10&page=4&sort=selling_price&direction=desc')->assertOk();
        $page->assertJsonPath('meta.current_page', 4)->assertJsonPath('meta.last_page', 4);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/products?per_page=10&search=PAGE-003&sort=selling_price&direction=desc')->assertOk()->assertJsonPath('data.0.sku', 'PAGE-003');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/products?per_page=10&page=99')->assertOk()->assertJsonCount(0, 'data');
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
