<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\MarketplaceInventoryReservation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_login_update_profile_and_manage_an_address(): void
    {
        $this->postJson('/api/v1/marketplace/auth/register', [
            'name' => 'Marketplace Customer',
            'email' => 'customer@example.com',
            'password' => 'CorrectPassword1!',
            'password_confirmation' => 'CorrectPassword1!',
            'phone' => '0912345678',
            'terms_accepted' => true,
        ])->assertCreated()->assertJsonPath('data.email', 'customer@example.com');

        $this->getJson('/api/v1/marketplace/auth/me')->assertOk()->assertJsonPath('data.phone', '0912345678');
        $this->patchJson('/api/v1/marketplace/auth/me', ['name' => 'Updated Customer', 'locale' => 'my'])
            ->assertOk()->assertJsonPath('data.name', 'Updated Customer')->assertJsonPath('data.locale', 'my');

        $this->postJson('/api/v1/marketplace/me/addresses', [
            'recipient_name' => 'Updated Customer',
            'address_line_1' => 'No. 1 Main Street',
            'city' => 'Yangon',
            'country' => 'MM',
            'is_default' => true,
        ])->assertCreated()->assertJsonPath('data.is_default', true);

        $this->patchJson('/api/v1/marketplace/auth/me', ['phone' => '09987654321'])
            ->assertOk()->assertJsonCount(1, 'data.addresses')->assertJsonPath('data.phone', '09987654321');

        $this->postJson('/api/v1/marketplace/auth/logout')->assertOk();
        $this->postJson('/api/v1/marketplace/auth/login', ['email' => 'customer@example.com', 'password' => 'CorrectPassword1!'])
            ->assertOk()->assertJsonPath('data.email', 'customer@example.com')->assertJsonCount(1, 'data.addresses');
    }

    public function test_customer_registration_rejects_duplicate_email_without_creating_an_extra_profile(): void
    {
        $payload = [
            'name' => 'Marketplace Customer',
            'email' => 'duplicate@example.com',
            'password' => 'CorrectPassword1!',
            'password_confirmation' => 'CorrectPassword1!',
            'phone' => '0912345678',
            'terms_accepted' => true,
        ];

        $this->postJson('/api/v1/marketplace/auth/register', $payload)->assertCreated();
        $this->postJson('/api/v1/marketplace/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('customer_profiles', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_customer_registration_rejects_seven_but_accepts_eight_characters(): void
    {
        $basePayload = [
            'name' => 'Short Password Customer',
            'phone' => '0912345678',
            'terms_accepted' => true,
        ];

        $this->postJson('/api/v1/marketplace/auth/register', $basePayload + [
            'email' => 'seven@example.com',
            'password' => 'Aa1!aaa',
            'password_confirmation' => 'Aa1!aaa',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/marketplace/auth/register', $basePayload + [
            'email' => 'eight@example.com',
            'password' => 'Aa1!aaaa',
            'password_confirmation' => 'Aa1!aaaa',
        ])->assertCreated();
    }

    public function test_customer_registration_accepts_twelve_characters_and_rejects_confirmation_mismatch(): void
    {
        $payload = [
            'name' => 'Long Password Customer',
            'email' => 'long-password@example.com',
            'phone' => '0912345678',
            'terms_accepted' => true,
        ];

        $this->postJson('/api/v1/marketplace/auth/register', $payload + [
            'password' => 'Ab1!cdefghij',
            'password_confirmation' => 'Ab1!cdefghik',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/marketplace/auth/register', $payload + [
            'password' => 'Ab1!cdefghij',
            'password_confirmation' => 'Ab1!cdefghij',
        ])->assertCreated();
    }

    public function test_customer_registration_does_not_create_business_membership_or_platform_role(): void
    {
        $this->postJson('/api/v1/marketplace/auth/register', [
            'name' => 'Marketplace Customer',
            'email' => 'permissions@example.com',
            'password' => 'CorrectPassword1!',
            'password_confirmation' => 'CorrectPassword1!',
            'terms_accepted' => true,
        ])->assertCreated();

        $user = User::query()->where('email', 'permissions@example.com')->firstOrFail();

        $this->assertNull($user->platform_role);
        $this->assertDatabaseMissing('tenant_memberships', ['user_id' => $user->id]);
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $user->id, 'status' => 'active']);
    }

    public function test_existing_business_session_can_use_customer_marketplace_profile_without_role_conversion(): void
    {
        [$tenant, $businessAdmin] = $this->businessWithAdmin('MARKETPLACE-SESSION', 'marketplace-session@example.com');
        CustomerProfile::create(['user_id' => $businessAdmin->id, 'status' => 'active']);

        $this->actingAs($businessAdmin, 'sanctum')
            ->getJson('/api/v1/marketplace/auth/me')
            ->assertOk();

        $this->assertNull($businessAdmin->refresh()->platform_role);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $tenant->id, 'user_id' => $businessAdmin->id]);
    }

    public function test_business_admin_cannot_access_platform_marketplace_approval(): void
    {
        [, $businessAdmin] = $this->businessWithAdmin('MARKETPLACE-A', 'marketplace-a@example.com');

        $this->actingAs($businessAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/stores')
            ->assertForbidden();
    }

    public function test_super_admin_approval_is_audited_and_public_catalog_excludes_inactive_businesses(): void
    {
        [$tenant] = $this->businessWithAdmin('MARKETPLACE-B', 'marketplace-b@example.com');
        $store = MarketplaceStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Marketplace B',
            'slug' => 'marketplace-b',
            'approval_status' => 'pending',
            'is_active' => true,
        ]);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/approval', ['approval_status' => 'approved'])
            ->assertOk()->assertJsonPath('data.approval_status', 'approved');
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.store_approval_updated', 'tenant_id' => $tenant->id]);

        $product = $this->publicProduct($tenant, 'Public Product');
        MarketplaceListing::create([
            'tenant_id' => $tenant->id,
            'marketplace_store_id' => $store->id,
            'product_id' => $product->id,
            'slug' => 'public-product',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/marketplace/products')->assertOk()->assertJsonCount(1, 'data');
        $tenant->update(['status' => 'suspended']);
        $this->getJson('/api/v1/marketplace/products')->assertOk()->assertJsonCount(0, 'data');
        $tenant->update(['status' => 'archived']);
        $this->getJson('/api/v1/marketplace/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_platform_vendor_management_is_paginated_tenant_safe_and_reports_real_operations(): void
    {
        [$tenant, $businessAdmin] = $this->businessWithAdmin('MARKETPLACE-VENDOR-MONITOR', 'vendor-monitor@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Vendor Monitor Store', 'slug' => 'vendor-monitor-store', 'approval_status' => 'pending', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Vendor Monitor Product');
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => 'vendor-monitor-product', 'status' => 'draft']);
        $customer = User::factory()->create(['email' => 'vendor-monitor-customer@example.com']);
        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'ORD-VENDOR-MONITOR', 'currency_code' => 'MMK', 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 1500, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'grand_total' => 1500, 'delivery_address' => ['city' => 'Yangon'], 'idempotency_key' => 'vendor-monitor-order', 'request_hash' => hash('sha256', 'vendor-monitor-order')]);
        MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'vendor_order_number' => 'VND-VENDOR-MONITOR', 'status' => 'delivered', 'fulfillment_status' => 'delivered', 'subtotal' => 1500, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'total' => 1500, 'currency_code' => 'MMK']);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/detail')->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/stores?approval_status=pending&per_page=1')->assertOk()->assertJsonPath('data.0.id', $store->uuid)->assertJsonPath('data.0.order_count', 1)->assertJsonPath('meta.summary.pending', 1);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/detail?from=2026-01-01&to=2026-12-31')->assertOk()->assertJsonPath('data.store.id', $store->uuid)->assertJsonPath('data.summary.total_orders', 1)->assertJsonPath('data.summary.delivered_orders', 1)->assertJsonPath('data.summary.gross_sales', '1500.00')->assertJsonPath('data.products.0.id', $listing->uuid)->assertJsonPath('data.orders.0.vendor_order_number', 'VND-VENDOR-MONITOR');
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/approval', ['approval_status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/status', ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/approval', ['approval_status' => 'rejected', 'reason' => 'Eligibility review incomplete'])->assertOk();
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.store_approval_updated', 'tenant_id' => $tenant->id]);
    }

    public function test_vendor_cannot_read_or_update_another_business_listing(): void
    {
        [$tenantA, $adminA] = $this->businessWithAdmin('MARKETPLACE-C', 'marketplace-c@example.com');
        [$tenantB] = $this->businessWithAdmin('MARKETPLACE-D', 'marketplace-d@example.com');
        $storeB = MarketplaceStore::create(['tenant_id' => $tenantB->id, 'name' => 'Marketplace D', 'slug' => 'marketplace-d', 'approval_status' => 'approved', 'is_active' => true]);
        $productB = $this->publicProduct($tenantB, 'Business D Product');
        $listingB = MarketplaceListing::create(['tenant_id' => $tenantB->id, 'marketplace_store_id' => $storeB->id, 'product_id' => $productB->id, 'slug' => 'business-d-product', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->patchJson('/api/v1/vendor/marketplace/listings/'.$listingB->uuid, ['status' => 'archived'])
            ->assertNotFound();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/vendor/marketplace/listings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_vendor_listing_validation_and_pagination_are_tenant_scoped(): void
    {
        [$tenant, $admin] = $this->businessWithAdmin('MARKETPLACE-E', 'marketplace-e@example.com');
        MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Marketplace E', 'slug' => 'marketplace-e', 'approval_status' => 'approved', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Business E Product');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/listings', ['product_id' => 'not-a-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/listings', ['product_id' => $product->uuid, 'status' => 'draft'])
            ->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/listings?per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 1);
    }

    public function test_public_catalog_supports_sku_price_filters_and_slug_detail_routes(): void
    {
        [$tenant] = $this->businessWithAdmin('MARKETPLACE-F', 'marketplace-f@example.com');
        $store = MarketplaceStore::create([
            'tenant_id' => $tenant->id,
            'name' => 'Marketplace F',
            'slug' => 'marketplace-f',
            'approval_status' => 'approved',
            'is_active' => true,
        ]);
        $product = $this->publicProduct($tenant, 'Public Filter Product');
        $product->variants()->firstOrFail()->update(['sku' => 'FILTER-SKU-001', 'selling_price' => 2400]);
        $listing = MarketplaceListing::create([
            'tenant_id' => $tenant->id,
            'marketplace_store_id' => $store->id,
            'product_id' => $product->id,
            'slug' => 'public-filter-product',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/marketplace/products?search=FILTER-SKU-001&min_price=2000&sort=price-high&per_page=12&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('data.0.id', $listing->uuid)
            ->assertJsonPath('data.0.store_slug', 'marketplace-f')
            ->assertJsonPath('data.0.category_slug', 'marketplace-f-general');

        $this->getJson('/api/v1/marketplace/products?store=marketplace-f&category=marketplace-f-general')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $listing->uuid);

        $this->getJson('/api/v1/marketplace/products/public-filter-product')
            ->assertOk()
            ->assertJsonPath('data.slug', 'public-filter-product')
            ->assertJsonPath('data.price', '2400.0000');

        $this->getJson('/api/v1/marketplace/stores/marketplace-f')
            ->assertOk()
            ->assertJsonPath('data.slug', 'marketplace-f')
            ->assertJsonPath('data.listings.0.slug', 'public-filter-product');
    }

    public function test_vendor_overview_uses_tenant_scoped_listing_and_inventory_data_without_fabricating_orders(): void
    {
        [$tenant, $admin] = $this->businessWithAdmin('MARKETPLACE-G', 'marketplace-g@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Marketplace G', 'slug' => 'marketplace-g', 'approval_status' => 'approved', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Business G Product');
        MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => 'business-g-product', 'status' => 'published', 'published_at' => now()]);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/overview')
            ->assertOk()
            ->assertJsonPath('data.metrics.published_products', 1)
            ->assertJsonPath('data.metrics.pending_orders', 0)
            ->assertJsonPath('data.metrics.marketplace_revenue', '0.00')
            ->assertJsonPath('data.capabilities.orders', true);
    }

    public function test_vendor_overview_allocates_mixed_branch_order_once_and_rejects_unauthorized_branch(): void
    {
        [$tenant, $admin] = $this->businessWithAdmin('MARKETPLACE-OVERVIEW-BRANCH', 'marketplace-overview-branch@example.com');
        [$otherTenant, $otherAdmin] = $this->businessWithAdmin('MARKETPLACE-OVERVIEW-OTHER', 'marketplace-overview-other@example.com');
        $branchA = Store::create(['tenant_id' => $tenant->id, 'name' => 'Overview Branch A', 'code' => 'OV-A', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        $branchB = Store::create(['tenant_id' => $tenant->id, 'name' => 'Overview Branch B', 'code' => 'OV-B', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        $locationA = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'store', 'store_id' => $branchA->id, 'name' => 'Overview A Stock', 'code' => 'OV-A-STOCK', 'status' => 'active']);
        $locationB = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'store', 'store_id' => $branchB->id, 'name' => 'Overview B Stock', 'code' => 'OV-B-STOCK', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Overview Split Product', 'slug' => 'overview-split-product', 'status' => 'active', 'track_inventory' => true, 'created_by' => $admin->id]);
        $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => 'OV-SPLIT', 'selling_price' => 100, 'reorder_level' => 1, 'status' => 'active', 'is_default' => true]);
        $balanceA = InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $locationA->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 5, 'reserved_quantity' => 1, 'average_cost' => 50, 'version' => 0]);
        $balanceB = InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $locationB->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 5, 'reserved_quantity' => 1, 'average_cost' => 50, 'version' => 0]);
        $marketplaceStore = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Overview Marketplace', 'slug' => 'overview-marketplace', 'approval_status' => 'approved', 'is_active' => true]);
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $marketplaceStore->id, 'product_id' => $product->id, 'slug' => 'overview-split-product', 'status' => 'published', 'published_at' => now()]);
        $customer = User::factory()->create(['email' => 'marketplace-overview-customer@example.com']);
        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'OVERVIEW-SPLIT-001', 'currency_code' => 'MMK', 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 200, 'grand_total' => 200, 'delivery_address' => [], 'idempotency_key' => 'overview-split-order', 'request_hash' => hash('sha256', 'overview-split-order')]);
        $vendorOrder = MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $marketplaceStore->id, 'vendor_order_number' => 'V-OVERVIEW-SPLIT-001', 'status' => 'delivered', 'fulfillment_status' => 'delivered', 'subtotal' => 200, 'total' => 200, 'currency_code' => 'MMK']);
        $item = MarketplaceOrderItem::create(['marketplace_order_id' => $order->id, 'marketplace_vendor_order_id' => $vendorOrder->id, 'tenant_id' => $tenant->id, 'marketplace_listing_id' => $listing->id, 'product_id' => $product->id, 'product_variant_id' => $variant->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $variant->sku, 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200, 'currency_code' => 'MMK']);
        MarketplaceInventoryReservation::create(['marketplace_order_id' => $order->id, 'marketplace_order_item_id' => $item->id, 'tenant_id' => $tenant->id, 'inventory_balance_id' => $balanceA->id, 'inventory_location_id' => $locationA->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'status' => 'confirmed', 'idempotency_key' => 'overview-split-a', 'expires_at' => now()->addHour()]);
        MarketplaceInventoryReservation::create(['marketplace_order_id' => $order->id, 'marketplace_order_item_id' => $item->id, 'tenant_id' => $tenant->id, 'inventory_balance_id' => $balanceB->id, 'inventory_location_id' => $locationB->id, 'product_variant_id' => $variant->id, 'quantity' => 1, 'status' => 'confirmed', 'idempotency_key' => 'overview-split-b', 'expires_at' => now()->addHour()]);
        MarketplacePayment::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_vendor_order_id' => $vendorOrder->id, 'marketplace_store_id' => $marketplaceStore->id, 'method' => 'cod', 'status' => 'paid', 'amount' => 200, 'amount_received' => 200, 'currency_code' => 'MMK', 'idempotency_key' => 'overview-split-payment', 'paid_at' => now()]);

        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/vendor/marketplace/overview?branch_id='.$branchA->uuid)
            ->assertOk()->assertJsonPath('data.metrics.total_orders', 1)->assertJsonPath('data.metrics.gross_order_value', '100.00')->assertJsonPath('data.metrics.paid_revenue', '100.00')->assertJsonPath('data.top_products.0.revenue', '100.00');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/vendor/marketplace/overview')
            ->assertOk()->assertJsonPath('data.metrics.total_orders', 1)->assertJsonPath('data.metrics.gross_order_value', '200.00');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/vendor/marketplace/overview?branch_id='.$otherTenant->uuid)->assertForbidden();
        $this->actingAs($otherAdmin, 'sanctum')->withHeader('X-Tenant-Id', $otherTenant->uuid)->getJson('/api/v1/vendor/marketplace/overview?branch_id='.$branchA->uuid)->assertForbidden();
    }

    public function test_vendor_product_catalog_is_paginated_branch_scoped_and_tenant_safe(): void
    {
        [$tenantA, $adminA] = $this->businessWithAdmin('MARKETPLACE-CATALOG-A', 'marketplace-catalog-a@example.com');
        [$tenantB, $adminB] = $this->businessWithAdmin('MARKETPLACE-CATALOG-B', 'marketplace-catalog-b@example.com');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];

        $branchA = Store::create(['tenant_id' => $tenantA->id, 'name' => 'Catalog Branch A', 'code' => 'CAT-A', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $adminA->id]);
        $branchB = Store::create(['tenant_id' => $tenantA->id, 'name' => 'Catalog Branch B', 'code' => 'CAT-B', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $adminA->id]);
        $locationA = InventoryLocation::create(['tenant_id' => $tenantA->id, 'location_type' => 'store', 'store_id' => $branchA->id, 'name' => 'Branch A Stock', 'code' => 'CAT-A-STOCK', 'status' => 'active']);
        $locationB = InventoryLocation::create(['tenant_id' => $tenantA->id, 'location_type' => 'store', 'store_id' => $branchB->id, 'name' => 'Branch B Stock', 'code' => 'CAT-B-STOCK', 'status' => 'active']);
        $category = Category::create(['tenant_id' => $tenantA->id, 'name' => 'Catalog Category', 'slug' => 'catalog-category', 'status' => 'active']);
        $shared = Product::create(['tenant_id' => $tenantA->id, 'category_id' => $category->id, 'name' => 'Shared Branch Product', 'slug' => 'shared-branch-product', 'status' => 'active', 'track_inventory' => true, 'created_by' => $adminA->id]);
        $sharedVariant = ProductVariant::create(['tenant_id' => $tenantA->id, 'product_id' => $shared->id, 'sku' => 'CAT-SHARED', 'selling_price' => 100, 'reorder_level' => 5, 'status' => 'active', 'is_default' => true]);
        InventoryBalance::create(['tenant_id' => $tenantA->id, 'inventory_location_id' => $locationA->id, 'product_variant_id' => $sharedVariant->id, 'on_hand_quantity' => 7, 'reserved_quantity' => 1, 'average_cost' => 50, 'version' => 0]);
        InventoryBalance::create(['tenant_id' => $tenantA->id, 'inventory_location_id' => $locationB->id, 'product_variant_id' => $sharedVariant->id, 'on_hand_quantity' => 2, 'reserved_quantity' => 0, 'average_cost' => 50, 'version' => 0]);

        $branchBOnly = Product::create(['tenant_id' => $tenantA->id, 'name' => 'Branch B Only Product', 'slug' => 'branch-b-only-product', 'status' => 'active', 'track_inventory' => true, 'created_by' => $adminA->id]);
        $branchBVariant = ProductVariant::create(['tenant_id' => $tenantA->id, 'product_id' => $branchBOnly->id, 'sku' => 'CAT-B-ONLY', 'selling_price' => 200, 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenantA->id, 'inventory_location_id' => $locationB->id, 'product_variant_id' => $branchBVariant->id, 'on_hand_quantity' => 10, 'reserved_quantity' => 0, 'average_cost' => 100, 'version' => 0]);

        $marketplaceStore = MarketplaceStore::create(['tenant_id' => $tenantA->id, 'name' => 'Catalog Marketplace', 'slug' => 'catalog-marketplace', 'approval_status' => 'approved', 'is_active' => true]);
        MarketplaceListing::create(['tenant_id' => $tenantA->id, 'marketplace_store_id' => $marketplaceStore->id, 'product_id' => $shared->id, 'slug' => 'shared-branch-product', 'status' => 'published', 'published_at' => now()]);
        $otherTenantBranch = Store::create(['tenant_id' => $tenantB->id, 'name' => 'Other Tenant Branch', 'code' => 'OTHER-BRANCH', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $adminB->id]);

        $branchResponse = $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)
            ->getJson('/api/v1/vendor/marketplace/products?branch_id='.$branchA->uuid.'&per_page=10&page=1&listing_status=published')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.summary.published_products', 1)
            ->assertJsonPath('data.0.id', $shared->uuid)
            ->assertJsonPath('data.0.listing.status', 'published');
        $this->assertEquals(6.0, (float) $branchResponse->json('data.0.available_stock'));

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)
            ->getJson('/api/v1/vendor/marketplace/products?branch_id='.$branchB->uuid.'&per_page=10&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)
            ->getJson('/api/v1/vendor/marketplace/products?branch_id='.$branchB->uuid.'&per_page=10&page=1&search=CAT-SHARED')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'CAT-SHARED');

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)
            ->getJson('/api/v1/vendor/marketplace/products?branch_id='.$branchB->uuid.'&per_page=0')
            ->assertUnprocessable();

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)
            ->getJson('/api/v1/vendor/marketplace/products?branch_id='.$otherTenantBranch->uuid)
            ->assertForbidden();

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/vendor/marketplace/products')
            ->assertForbidden();
    }

    public function test_vendor_can_update_own_store_and_upload_public_branding_media(): void
    {
        Storage::fake('public');
        [$tenant, $admin] = $this->businessWithAdmin('MARKETPLACE-H', 'marketplace-h@example.com');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->patchJson('/api/v1/vendor/marketplace/store', ['name' => 'Updated Marketplace H', 'description' => 'Public store description', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.name', 'Updated Marketplace H')->assertJsonPath('data.is_active', false);

        $response = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->post('/api/v1/vendor/marketplace/store/media', ['media_type' => 'logo', 'image' => UploadedFile::fake()->image('logo.png')]);
        $path = $tenant->marketplaceStore()->firstOrFail()->logo_path;

        $response->assertOk()->assertJsonPath('data.logo_url', '/storage/'.$path);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.store_media_updated', 'tenant_id' => $tenant->id]);
    }

    public function test_vendor_qr_payment_configuration_is_tenant_scoped_and_removable(): void
    {
        Storage::fake('public');
        [$tenantA, $adminA] = $this->businessWithAdmin('MARKETPLACE-QR-A', 'marketplace-qr-a@example.com');
        [$tenantB] = $this->businessWithAdmin('MARKETPLACE-QR-B', 'marketplace-qr-b@example.com');

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->patchJson('/api/v1/vendor/marketplace/store', ['qr_payment_provider' => 'KBZPay', 'qr_payment_account_name' => 'QR Business A', 'qr_payment_phone' => '0912345678', 'qr_payment_instructions' => 'Pay exact amount.', 'qr_payment_enabled' => true])
            ->assertOk()->assertJsonPath('data.qr_payment_provider', 'KBZPay')->assertJsonPath('data.qr_payment_enabled', true);

        $response = $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->post('/api/v1/vendor/marketplace/store/media', ['media_type' => 'qr', 'image' => UploadedFile::fake()->image('payment-qr.png')]);
        $path = $tenantA->marketplaceStore()->firstOrFail()->qr_payment_qr_path;
        $response->assertOk()->assertJsonPath('data.qr_payment_qr_url', '/storage/'.$path);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->patchJson('/api/v1/vendor/marketplace/store', ['qr_payment_account_name' => 'Should not change'])->assertForbidden();

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->deleteJson('/api/v1/vendor/marketplace/store/media/qr')->assertOk()->assertJsonPath('data.qr_payment_qr_url', null);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_vendor_listing_images_are_tenant_scoped_and_validated(): void
    {
        Storage::fake('public');
        [$tenantA, $adminA] = $this->businessWithAdmin('MARKETPLACE-I', 'marketplace-i@example.com');
        [$tenantB] = $this->businessWithAdmin('MARKETPLACE-J', 'marketplace-j@example.com');
        $storeB = MarketplaceStore::create(['tenant_id' => $tenantB->id, 'name' => 'Marketplace J', 'slug' => 'marketplace-j', 'approval_status' => 'approved', 'is_active' => true]);
        $productB = $this->publicProduct($tenantB, 'Business J Product');
        $listingB = MarketplaceListing::create(['tenant_id' => $tenantB->id, 'marketplace_store_id' => $storeB->id, 'product_id' => $productB->id, 'slug' => 'business-j-product', 'status' => 'draft']);

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->post('/api/v1/vendor/marketplace/listings/'.$listingB->uuid.'/images', ['images' => [UploadedFile::fake()->image('private.png')]])
            ->assertNotFound();

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->postJson('/api/v1/vendor/marketplace/listings', ['product_id' => $this->publicProduct($tenantA, 'Business I Product')->uuid, 'status' => 'draft'])
            ->assertCreated();
    }

    private function businessWithAdmin(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $admin];
    }

    private function publicProduct(Tenant $tenant, string $name): Product
    {
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General', 'slug' => strtolower($tenant->business_code).'-general', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'status' => 'active', 'track_inventory' => false, 'tax_exempt' => false]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => strtoupper($tenant->business_code).'-SKU', 'selling_price' => 1500, 'status' => 'active']);

        return $product;
    }
}
