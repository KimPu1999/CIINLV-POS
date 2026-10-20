<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\MarketplaceInventoryReservation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\MarketplaceInventoryReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_cart_uses_server_price_and_isolated_from_other_customers(): void
    {
        [$tenant, , $listing, $variant] = $this->vendor('CHECKOUT-A', 1250, 5);
        $customerA = $this->customer('buyer-a@example.com');
        $customerB = $this->customer('buyer-b@example.com');

        $this->actingAs($customerA, 'sanctum')->postJson('/api/v1/marketplace/cart/items', [
            'listing_id' => $listing->uuid,
            'variant_id' => $variant->uuid,
            'quantity' => 2,
            'unit_price' => 1,
        ])->assertCreated()->assertJsonPath('data.items.0.unit_price', '1250.0000')->assertJsonPath('data.items.0.quantity', '2.000');

        $this->actingAs($customerB, 'sanctum')->getJson('/api/v1/marketplace/cart')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseHas('marketplace_cart_items', ['tenant_id' => $tenant->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
    }

    public function test_mixed_vendor_checkout_splits_orders_reserves_stock_and_is_idempotent(): void
    {
        [, , $listingA, $variantA] = $this->vendor('CHECKOUT-B', 1000, 5);
        [, , $listingB, $variantB] = $this->vendor('CHECKOUT-C', 2000, 5);
        $customer = $this->customer('buyer-c@example.com');
        $address = $this->address($customer);
        $headers = ['Idempotency-Key' => 'checkout-mixed-1'];

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listingA->uuid, 'variant_id' => $variantA->uuid, 'quantity' => 2])->assertCreated();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listingB->uuid, 'variant_id' => $variantB->uuid, 'quantity' => 1])->assertCreated();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/checkout/quote', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertOk()->assertJsonPath('data.subtotal', '4000.00')->assertJsonPath('data.grand_total', '4000.00')->assertJsonPath('data.payment_methods.0', 'cod');

        $response = $this->actingAs($customer, 'sanctum')->withHeaders($headers)->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertCreated()->assertJsonPath('data.payment_status', 'pending')->assertJsonPath('data.vendor_orders.0.status', 'pending');
        $orderId = $response->json('data.id');
        $this->assertDatabaseCount('marketplace_vendor_orders', 2);
        $this->assertDatabaseCount('marketplace_inventory_reservations', 2);
        $this->assertDatabaseHas('marketplace_payments', ['method' => 'cod', 'status' => 'pending', 'amount' => '4000.00']);

        $this->actingAs($customer, 'sanctum')->withHeaders($headers)->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertOk()->assertJsonPath('idempotent_replay', true)->assertJsonPath('data.id', $orderId);
        $this->assertDatabaseCount('marketplace_orders', 1);
    }

    public function test_stock_reservation_prevents_a_second_customer_from_overselling(): void
    {
        [$tenant, , $listing, $variant] = $this->vendor('CHECKOUT-CONCURRENCY', 1000, 1);
        $customerA = $this->customer('buyer-concurrency-a@example.com');
        $customerB = $this->customer('buyer-concurrency-b@example.com');
        $addressA = $this->address($customerA);
        $addressB = $this->address($customerB);

        $this->actingAs($customerA, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $this->actingAs($customerB, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();

        $this->actingAs($customerA, 'sanctum')->withHeader('Idempotency-Key', 'checkout-concurrency-a')->postJson('/api/v1/marketplace/checkout', ['address_id' => $addressA->id, 'payment_method' => 'cod'])->assertCreated();
        $this->actingAs($customerB, 'sanctum')->withHeader('Idempotency-Key', 'checkout-concurrency-b')->postJson('/api/v1/marketplace/checkout', ['address_id' => $addressB->id, 'payment_method' => 'cod'])->assertUnprocessable();

        $this->assertDatabaseHas('inventory_balances', ['tenant_id' => $tenant->id, 'product_variant_id' => $variant->id, 'reserved_quantity' => '1.000']);
        $this->assertDatabaseCount('marketplace_orders', 1);
    }

    public function test_archived_vendor_is_revalidated_before_checkout(): void
    {
        [$tenant, , $listing, $variant] = $this->vendor('CHECKOUT-ARCHIVED', 1000, 2);
        $customer = $this->customer('buyer-archived@example.com');
        $address = $this->address($customer);

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $tenant->update(['status' => 'archived']);

        $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'checkout-archived')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertUnprocessable();
        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_checkout_rejects_out_of_stock_and_inactive_vendor(): void
    {
        [, $store, $listing, $variant] = $this->vendor('CHECKOUT-D', 1000, 0);
        $customer = $this->customer('buyer-d@example.com');
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertUnprocessable();

        $store->update(['is_active' => false]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertUnprocessable();
    }

    public function test_vendor_order_endpoint_cannot_cross_tenant_boundary(): void
    {
        [$tenantA, , $listingA, $variantA] = $this->vendor('CHECKOUT-E', 1000, 4);
        [$tenantB, , , , $adminB] = $this->vendor('CHECKOUT-F', 1000, 4);
        $customer = $this->customer('buyer-e@example.com');
        $address = $this->address($customer);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listingA->uuid, 'variant_id' => $variantA->uuid, 'quantity' => 1])->assertCreated();
        $order = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'checkout-tenant-boundary')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/vendor/marketplace/orders/'.$order)->assertNotFound();
        $this->assertDatabaseHas('marketplace_orders', ['customer_user_id' => $customer->id]);
    }

    public function test_expired_reservation_releases_reserved_quantity_once(): void
    {
        [, , $listing, $variant] = $this->vendor('CHECKOUT-G', 1000, 4);
        $customer = $this->customer('buyer-f@example.com');
        $address = $this->address($customer);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 2])->assertCreated();
        $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'checkout-release')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated();

        $reservation = MarketplaceInventoryReservation::firstOrFail();
        $reservation->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(1, app(MarketplaceInventoryReservationService::class)->releaseExpired());
        $this->assertSame(0, app(MarketplaceInventoryReservationService::class)->releaseExpired());
        $this->assertDatabaseHas('marketplace_inventory_reservations', ['id' => $reservation->id, 'status' => 'expired']);
        $this->assertSame('0.000', (string) InventoryBalance::where('product_variant_id', $variant->id)->value('reserved_quantity'));
    }

    public function test_super_admin_can_collect_cod_once_and_customer_cannot(): void
    {
        [, , $listing, $variant] = $this->vendor('CHECKOUT-H', 1000, 4);
        $customer = $this->customer('buyer-g@example.com');
        $address = $this->address($customer);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $order = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'checkout-cod-collect')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/admin/marketplace/orders/'.$order.'/cod/collect')->assertForbidden();
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/marketplace/orders/'.$order.'/cod/collect', ['verification_note' => 'Cash received'])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/marketplace/orders/'.$order.'/cod/collect')->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->assertDatabaseHas('marketplace_payments', ['status' => 'paid']);
        $this->assertDatabaseCount('marketplace_payments', 1);
    }

    private function vendor(string $code, int $price, int $stock): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => strtolower($code).'@example.com']);
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

    private function address(User $customer): CustomerAddress
    {
        return CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 1 Main Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
    }
}
