<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\MarketplaceCartItem;
use App\Models\MarketplaceListing;
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

class ProductSizeVariantTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_size_variants_with_independent_stock(): void
    {
        [$tenant, $admin, $location] = $this->business('SIZE-CREATE');

        $response = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/products', [
            'name' => 'Size Shirt',
            'has_sizes' => true,
            'track_inventory' => true,
            'inventory_location_id' => $location->uuid,
            'variants' => [
                ['sku' => 'SHIRT-M', 'attributes' => ['size' => 'M'], 'selling_price' => '25.00', 'stock_quantity' => '4', 'is_default' => true],
                ['sku' => 'SHIRT-L', 'attributes' => ['size' => 'L'], 'selling_price' => '27.00', 'stock_quantity' => '2'],
            ],
        ])->assertCreated();

        $product = Product::where('tenant_id', $tenant->id)->where('name', 'Size Shirt')->firstOrFail();
        $this->assertTrue($product->has_sizes);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'SHIRT-M', 'is_default' => true]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'SHIRT-L', 'is_default' => false]);
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'product_variant_id' => ProductVariant::where('sku', 'SHIRT-M')->value('id'), 'on_hand_quantity' => '4.000']);
        $this->assertSame('27.0000', (string) collect($response->json('data.variants'))->firstWhere('sku', 'SHIRT-L')['selling_price']);
    }

    public function test_duplicate_sizes_are_rejected_without_creating_catalog_or_stock(): void
    {
        [$tenant, $admin, $location] = $this->business('SIZE-DUPLICATE');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/products', [
            'name' => 'Duplicate Size Product',
            'has_sizes' => true,
            'inventory_location_id' => $location->uuid,
            'variants' => [
                ['sku' => 'DUP-M-1', 'attributes' => ['size' => 'M'], 'selling_price' => '10.00', 'stock_quantity' => '2'],
                ['sku' => 'DUP-M-2', 'attributes' => ['size' => 'm'], 'selling_price' => '11.00', 'stock_quantity' => '2'],
            ],
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('products', ['tenant_id' => $tenant->id, 'name' => 'Duplicate Size Product']);
    }

    public function test_marketplace_cart_and_checkout_keep_size_variants_separate_and_reserve_each_size(): void
    {
        [$tenant, $product, $medium, $large] = $this->marketplaceProduct();
        $customer = User::factory()->create(['email' => 'size-buyer@example.com', 'password' => Hash::make('CorrectPassword1!'), 'platform_role' => null]);
        $profile = CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);
        $address = CustomerAddress::create(['customer_profile_id' => $profile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'Size Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
        $listing = MarketplaceListing::where('product_id', $product->id)->firstOrFail();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $medium->uuid, 'quantity' => 1])->assertCreated();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $large->uuid, 'quantity' => 1])->assertCreated()->assertJsonCount(2, 'data.items');

        $order = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'size-checkout-1')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data');
        $items = collect($order['vendor_orders'][0]['items']);
        $this->assertSame(['L', 'M'], $items->pluck('size')->sort()->values()->all());
        $this->assertDatabaseHas('marketplace_inventory_reservations', ['product_variant_id' => $medium->id, 'quantity' => '1.000', 'status' => 'reserved']);
        $this->assertDatabaseHas('marketplace_inventory_reservations', ['product_variant_id' => $large->id, 'quantity' => '1.000', 'status' => 'reserved']);
    }

    public function test_out_of_stock_size_cannot_be_added_to_cart(): void
    {
        [$tenant, $product, , $large] = $this->marketplaceProduct();
        InventoryBalance::where('product_variant_id', $large->id)->update(['on_hand_quantity' => 0]);
        $customer = User::factory()->create(['email' => 'size-out@example.com', 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);
        $listing = MarketplaceListing::where('product_id', $product->id)->firstOrFail();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $large->uuid, 'quantity' => 1])->assertUnprocessable();
    }

    public function test_customer_can_change_a_cart_line_to_another_size_without_cross_tenant_access(): void
    {
        [$tenant, $product, $medium, $large] = $this->marketplaceProduct();
        $customer = User::factory()->create(['email' => 'size-edit@example.com', 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);
        $listing = MarketplaceListing::where('product_id', $product->id)->firstOrFail();

        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $medium->uuid, 'quantity' => 1])->assertCreated();
        $item = MarketplaceCartItem::where('product_variant_id', $medium->id)->firstOrFail();
        [$otherTenant] = $this->business('SIZE-EDIT-OTHER');
        $otherProduct = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Size Product', 'slug' => 'other-size-product', 'status' => 'active', 'track_inventory' => true, 'has_sizes' => true]);
        $otherVariant = ProductVariant::create(['tenant_id' => $otherTenant->id, 'product_id' => $otherProduct->id, 'sku' => 'OTHER-SIZE', 'attributes' => ['size' => 'XL'], 'selling_price' => '99.00', 'status' => 'active']);

        $this->actingAs($customer, 'sanctum')->patchJson('/api/v1/marketplace/cart/items/'.$item->uuid, ['variant_id' => $otherVariant->uuid, 'quantity' => 1])->assertUnprocessable();

        $this->actingAs($customer, 'sanctum')->patchJson('/api/v1/marketplace/cart/items/'.$item->uuid, ['variant_id' => $large->uuid, 'quantity' => 1])
            ->assertOk()
            ->assertJsonPath('data.items.0.variant_id', $large->uuid)
            ->assertJsonPath('data.items.0.size', 'L');
        $this->assertDatabaseCount('marketplace_cart_items', 1);
        $this->assertSame($tenant->id, ProductVariant::whereKey($large->id)->value('tenant_id'));
    }

    private function business(string $code): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'warehouse', 'name' => $code.' Warehouse', 'code' => $code.'-WH', 'status' => 'active']);

        return [$tenant, $admin, $location];
    }

    private function marketplaceProduct(): array
    {
        [$tenant, , $location] = $this->business('SIZE-MARKET');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Size Store', 'slug' => 'size-store', 'approval_status' => 'approved', 'is_active' => true]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Clothing', 'slug' => 'size-clothing', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Size Product', 'slug' => 'size-product', 'status' => 'active', 'track_inventory' => true, 'has_sizes' => true]);
        $medium = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => 'SIZE-M', 'attributes' => ['size' => 'M'], 'selling_price' => '10.00', 'is_default' => true, 'status' => 'active']);
        $large = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => 'SIZE-L', 'attributes' => ['size' => 'L'], 'selling_price' => '12.00', 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $medium->id, 'on_hand_quantity' => 2, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0]);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $large->id, 'on_hand_quantity' => 2, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0]);
        MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => 'size-listing', 'status' => 'published', 'published_at' => now()]);

        return [$tenant, $product, $medium, $large];
    }
}
