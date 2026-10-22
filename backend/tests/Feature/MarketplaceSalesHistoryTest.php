<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceSalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_sales_history_is_tenant_scoped_and_keeps_delivered_records(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('SALES-HISTORY-A', 1500, 4);
        [$otherTenant, , , , $otherAdmin] = $this->vendor('SALES-HISTORY-B', 1800, 4);
        $customer = $this->customer('sales-history-a@example.com');
        $order = $this->checkout($customer, $listing, $variant, 'sales-history-a');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $url = '/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid;

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.summary.sales_count', 0);

        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson($url.'/shipments', ['delivery_type' => 'self_delivery'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'sales-history-cod'])
            ->postJson($url.'/payments/cod/collect', ['amount_received' => 1500])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history?search='.$vendorOrder->vendor_order_number)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.receipt_available', true)
            ->assertJsonPath('data.0.payment_status', 'paid');

        foreach (['shipped', 'in_transit', 'delivered'] as $status) {
            $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
                ->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => $status])
                ->assertOk();
        }

        $tracking = $shipment['internal_tracking_id'];
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history?shipment_status=delivered&search='.$tracking)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shipment_status', 'delivered');

        // Delivered shipments leave the operational queue, but the underlying
        // shipment/order records remain available to Sales History.
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/orders?active_shipping=true')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('marketplace_shipments', ['internal_tracking_id' => $tracking, 'status' => 'delivered']);

        $this->actingAs($otherAdmin, 'sanctum')->withHeader('X-Tenant-Id', $otherTenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($otherAdmin, 'sanctum')->withHeader('X-Tenant-Id', $otherTenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history/'.$vendorOrder->uuid)
            ->assertNotFound();
    }

    public function test_refunded_sales_remain_visible_and_detail_contains_documents_and_events(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('SALES-HISTORY-C', 2200, 4);
        $customer = $this->customer('sales-history-c@example.com');
        $order = $this->checkout($customer, $listing, $variant, 'sales-history-c');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $url = '/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid;
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson($url.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'sales-history-refund'])
            ->postJson($url.'/payments/cod/collect', ['amount_received' => 2200])
            ->assertOk();
        $order->refresh()->update(['status' => 'refunded', 'payment_status' => 'refunded']);
        $order->payments()->firstOrFail()->update(['status' => 'refunded']);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history/'.$vendorOrder->uuid)
            ->assertOk()
            ->assertJsonPath('data.order_status', 'refunded')
            ->assertJsonPath('data.payment_status', 'refunded')
            ->assertJsonPath('data.invoice_available', true)
            ->assertJsonPath('data.receipt_available', false)
            ->assertJsonCount(1, 'data.items');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history?payment_status=refunded')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sales_history_supports_date_filters_pagination_and_csv_export(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('SALES-HISTORY-D', 900, 6);
        $customer = $this->customer('sales-history-d@example.com');
        $first = $this->checkout($customer, $listing, $variant, 'sales-history-d-1');
        $firstVendor = $first->vendorOrders()->firstOrFail();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$firstVendor->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'sales-history-d-pay-1'])
            ->postJson('/api/v1/vendor/marketplace/orders/'.$firstVendor->uuid.'/payments/cod/collect', ['amount_received' => 900])
            ->assertOk();
        $first->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();
        $firstVendor->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $second = $this->checkout($customer, $listing, $variant, 'sales-history-d-2');
        $secondVendor = $second->vendorOrders()->firstOrFail();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$secondVendor->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'sales-history-d-pay-2'])
            ->postJson('/api/v1/vendor/marketplace/orders/'.$secondVendor->uuid.'/payments/cod/collect', ['amount_received' => $second->grand_total])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/sales-history?date_from='.now()->subDay()->format('Y-m-d'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->get('/api/v1/vendor/marketplace/sales-history/export?search='.$secondVendor->vendor_order_number)
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    private function checkout(User $customer, MarketplaceListing $listing, ProductVariant $variant, string $key): MarketplaceOrder
    {
        $address = CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 1 Main Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $id = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');

        return MarketplaceOrder::where('uuid', $id)->firstOrFail();
    }

    private function vendor(string $code, int $price, int $stock): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => strtolower($code).'-admin@example.com']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => $code.' Store', 'slug' => strtolower($code).'-store', 'approval_status' => 'approved', 'is_active' => true]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General', 'slug' => strtolower($code).'-general', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => $code.' Product', 'slug' => strtolower($code).'-product', 'status' => 'active', 'track_inventory' => true, 'tax_exempt' => false]);
        $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $code.'-SKU', 'selling_price' => $price, 'status' => 'active']);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'warehouse', 'name' => $code.' Warehouse', 'code' => $code.'-WH', 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => $stock, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0]);
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => strtolower($code).'-listing', 'status' => 'published', 'published_at' => now()]);

        return [$tenant, $store, $listing, $variant, $admin];
    }

    private function customer(string $email): User
    {
        $customer = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!'), 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        return $customer;
    }
}
