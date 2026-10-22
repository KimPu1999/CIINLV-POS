<?php

namespace Tests\Feature;

use App\Models\BusinessNotification;
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
use App\Services\StockAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BusinessNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_marketplace_order_is_notified_once_and_failed_checkout_is_not(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('NOTIFY-ORDER-A', 75000, 4);
        $customer = $this->customer('notify-order@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);

        $order = $this->checkout($customer, $address, 'notify-order-checkout');

        $this->assertDatabaseHas('business_notifications', [
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'type' => 'marketplace_order_created',
        ]);
        $this->assertDatabaseCount('business_notifications', 1);

        $this->actingAs($customer, 'sanctum')
            ->withHeader('Idempotency-Key', 'notify-order-checkout')
            ->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertOk();
        $this->assertDatabaseCount('business_notifications', 1);

        $failedCustomer = $this->customer('notify-failed@example.com');
        $failedAddress = $this->address($failedCustomer);
        $this->actingAs($failedCustomer, 'sanctum')
            ->withHeader('Idempotency-Key', 'notify-failed-checkout')
            ->postJson('/api/v1/marketplace/checkout', ['address_id' => $failedAddress->id, 'payment_method' => 'cod'])
            ->assertUnprocessable();
        $this->assertDatabaseCount('marketplace_orders', 1);
        $this->assertDatabaseCount('business_notifications', 1);
        $this->assertNotNull($order->vendorOrders()->first());
    }

    public function test_notifications_are_tenant_scoped_and_read_apis_update_unread_count(): void
    {
        [$tenantA, , $listing, $variant, $adminA] = $this->vendor('NOTIFY-TENANT-A', 1000, 4);
        [$tenantB, , , , $adminB] = $this->vendor('NOTIFY-TENANT-B', 1000, 4);
        $customer = $this->customer('notify-tenant@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $this->checkout($customer, $address, 'notify-tenant-checkout');

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/business/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $notification = BusinessNotification::where('tenant_id', $tenantA->id)->where('user_id', $adminA->id)->firstOrFail();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/business/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->patchJson('/api/v1/business/notifications/'.$notification->uuid.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notification->uuid);
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/business/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_paid_cod_creates_one_payment_notification_and_inventory_thresholds_are_deduplicated(): void
    {
        [$tenant, , $listing, $variant, $admin, $location] = $this->vendor('NOTIFY-STOCK', 1000, 4);
        $variant->update(['reorder_level' => 2]);
        $customer = $this->customer('notify-payment@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'notify-payment-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $headers = ['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'notify-payment-collect'];

        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])
            ->assertCreated()
            ->json('data');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.receipt_available', true);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('business_notifications', 3);
        $this->assertDatabaseHas('business_notifications', ['tenant_id' => $tenant->id, 'type' => 'marketplace_payment_received']);

        $balance = InventoryBalance::where('inventory_location_id', $location->id)->where('product_variant_id', $variant->id)->firstOrFail();
        $balance->update(['on_hand_quantity' => 2, 'reserved_quantity' => 0]);
        app(StockAlertService::class)->evaluate($location, $variant->refresh());
        app(StockAlertService::class)->evaluate($location, $variant->refresh());
        $this->assertDatabaseCount('business_notifications', 4);
        $this->assertDatabaseHas('business_notifications', ['type' => 'inventory_low_stock']);

        $balance->update(['on_hand_quantity' => 0, 'reserved_quantity' => 0]);
        app(StockAlertService::class)->evaluate($location, $variant->refresh());
        $this->assertDatabaseCount('business_notifications', 5);
        $this->assertDatabaseHas('business_notifications', ['type' => 'inventory_out_of_stock']);

        $balance->update(['on_hand_quantity' => 5, 'reserved_quantity' => 0]);
        app(StockAlertService::class)->evaluate($location, $variant->refresh());
        $balance->update(['on_hand_quantity' => 2, 'reserved_quantity' => 0]);
        app(StockAlertService::class)->evaluate($location, $variant->refresh());
        $this->assertDatabaseCount('business_notifications', 6);
        $this->assertSame(2, BusinessNotification::where('type', 'inventory_low_stock')->count());
        $this->assertNotEmpty($shipment['id']);
    }

    private function addToCart(User $customer, MarketplaceListing $listing, ProductVariant $variant): void
    {
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
    }

    private function checkout(User $customer, CustomerAddress $address, string $key): MarketplaceOrder
    {
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

        return [$tenant, $store, $listing, $variant, $admin, $location];
    }

    private function customer(string $email): User
    {
        $customer = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!'), 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        return $customer;
    }

    private function address(User $customer): CustomerAddress
    {
        return CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 1 Main Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
    }
}
