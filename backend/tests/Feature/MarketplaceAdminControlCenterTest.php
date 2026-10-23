<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplacePayment;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceAdminControlCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_access_marketplace_control_center(): void
    {
        [, $businessAdmin] = $this->businessWithAdmin('CONTROL-A', 'control-a@example.com');
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($businessAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/dashboard')
            ->assertForbidden();

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/dashboard')
            ->assertOk()
            ->assertJsonPath('data.metrics.approved_vendors', 0)
            ->assertJsonPath('data.financial_capabilities.payouts.available', false);
    }

    public function test_web_super_admin_session_can_access_marketplace_control_center(): void
    {
        $superAdmin = User::factory()->create([
            'email' => 'marketplace-platform-admin@example.com',
            'password' => Hash::make('CorrectPassword1!'),
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $superAdmin->email,
            'password' => 'CorrectPassword1!',
            'client_type' => 'web',
        ])->assertOk()->assertJsonPath('data.platform_role', User::PLATFORM_ROLE_SUPER_ADMIN);

        $this->getJson('/api/v1/admin/marketplace/dashboard')->assertOk();
    }

    public function test_super_admin_personal_access_token_can_access_marketplace_control_center(): void
    {
        $superAdmin = User::factory()->create([
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);
        $token = $superAdmin->createToken('authorization-test', ['auth:mobile'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/admin/marketplace/dashboard')
            ->assertOk();
    }

    public function test_only_platform_super_admins_can_access_marketplace_control_center(): void
    {
        [$tenant, $businessAdmin] = $this->businessWithAdmin('CONTROL-ROLES', 'roles-business@example.com');
        $seller = User::factory()->create(['email' => 'roles-seller@example.com', 'status' => User::STATUS_ACTIVE]);
        $sellerRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'seller']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $seller->id, 'role_id' => $sellerRole->id, 'status' => 'active', 'joined_at' => now()]);
        $customer = User::factory()->create(['email' => 'roles-customer@example.com', 'status' => User::STATUS_ACTIVE]);

        $this->getJson('/api/v1/admin/marketplace/dashboard')->assertUnauthorized();

        foreach ([$businessAdmin, $seller, $customer] as $user) {
            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/admin/marketplace/dashboard')
                ->assertForbidden();
        }
    }

    public function test_super_admin_can_moderate_store_and_listing_with_audit_records(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-B', 'control-b@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Control B Store', 'slug' => 'control-b-store', 'approval_status' => 'pending', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Moderated Product');
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => 'moderated-product', 'status' => 'draft']);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/approval', ['approval_status' => 'approved'])
            ->assertOk();
        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/status', ['is_active' => false, 'reason' => 'Review hold'])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/listings/'.$listing->uuid.'/moderation', ['status' => 'published'])
            ->assertUnprocessable();

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/stores/'.$store->uuid.'/status', ['is_active' => true])
            ->assertOk();
        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/listings/'.$listing->uuid.'/moderation', ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.store_status_updated', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.listing_moderated', 'tenant_id' => $tenant->id]);
    }

    public function test_archived_business_cannot_have_a_public_listing_reenabled(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-C', 'control-c@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Control C Store', 'slug' => 'control-c-store', 'approval_status' => 'approved', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Archived Product');
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => 'archived-product', 'status' => 'draft']);
        $tenant->update(['status' => 'archived']);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/listings/'.$listing->uuid.'/moderation', ['status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_listing_monitoring_supports_authorized_filters_pagination_and_detail(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-LIST', 'control-list@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Listing Store', 'slug' => 'listing-store', 'approval_status' => 'approved', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Searchable Marketplace Product');
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => 'searchable-marketplace-product', 'status' => 'draft']);
        $otherTenant = Tenant::create(['name' => 'CONTROL-LIST-OTHER', 'slug' => 'control-list-other', 'business_code' => 'CONTROL-LIST-OTHER', 'status' => 'active', 'subscription_status' => 'active']);
        $otherStore = MarketplaceStore::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Listing Store', 'slug' => 'other-listing-store', 'approval_status' => 'approved', 'is_active' => true]);
        $otherProduct = $this->publicProduct($otherTenant, 'Other Marketplace Product');
        MarketplaceListing::create(['tenant_id' => $otherTenant->id, 'marketplace_store_id' => $otherStore->id, 'product_id' => $otherProduct->id, 'slug' => 'other-marketplace-product', 'status' => 'published']);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/listings?search=CONTROL-LIST-SKU&status=draft&per_page=25&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.summary.total', 2)
            ->assertJsonPath('data.0.id', $listing->uuid)
            ->assertJsonPath('data.0.sku', 'CONTROL-LIST-SKU')
            ->assertJsonPath('data.0.business_name', $tenant->name);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/listings/'.$listing->uuid)
            ->assertOk()
            ->assertJsonPath('data.id', $listing->uuid)
            ->assertJsonPath('data.publishing_eligibility.eligible', true)
            ->assertJsonStructure(['data' => ['variants', 'branches', 'publishing_eligibility', 'moderation_history']]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/listings?business_id='.$tenant->uuid.'&per_page=25')
            ->assertOk()->assertJsonPath('meta.total', 1);

        [, $businessAdmin] = $this->businessWithAdmin('CONTROL-LIST-ADMIN', 'control-list-admin@example.com');
        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/listings')->assertForbidden();
        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/listings/'.$listing->uuid)->assertForbidden();
    }

    public function test_listing_monitoring_returns_authoritative_metadata_for_a_large_catalog(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-LARGE', 'control-large@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Large Listing Store', 'slug' => 'large-listing-store', 'approval_status' => 'approved', 'is_active' => true]);
        $now = now();
        $products = [];
        for ($index = 1; $index <= 1000; $index++) {
            $slug = 'large-product-'.$index;
            $products[] = ['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Large Product '.$index, 'slug' => $slug, 'status' => 'active', 'track_inventory' => false, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('products')->insert($products);
        $productIds = DB::table('products')->where('tenant_id', $tenant->id)->whereIn('slug', array_column($products, 'slug'))->pluck('id', 'slug');
        $listings = [];
        foreach ($productIds as $slug => $productId) {
            $listings[] = ['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $productId, 'slug' => 'large-listing-'.$slug, 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('marketplace_listings')->insert($listings);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/listings?per_page=100&page=10&sort=name&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1000)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.current_page', 10)
            ->assertJsonPath('meta.last_page', 10)
            ->assertJsonCount(100, 'data');
    }

    public function test_reports_and_audit_logs_use_real_marketplace_records(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-D', 'control-d@example.com');
        MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Control D Store', 'slug' => 'control-d-store', 'approval_status' => 'approved', 'is_active' => true]);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/reports')->assertOk()->assertJsonPath('data.summary.orders', 0);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/audit-logs')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/settings')->assertOk()->assertJsonPath('data.configured', true);
    }

    public function test_order_monitoring_supports_authorized_filters_summaries_and_lightweight_rows(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-ORDERS', 'control-orders@example.com');
        $customer = User::factory()->create(['name' => 'Order Customer', 'email' => 'order-customer@example.com']);
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Order Store', 'slug' => 'order-store', 'approval_status' => 'approved', 'is_active' => true]);
        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'MON-001', 'currency_code' => 'MMK', 'status' => 'processing', 'payment_status' => 'pending', 'grand_total' => 1500, 'delivery_address' => [], 'idempotency_key' => 'mon-001', 'request_hash' => hash('sha256', 'mon-001')]);
        $vendor = MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'vendor_order_number' => 'V-MON-001', 'status' => 'processing', 'fulfillment_status' => 'active', 'total' => 1500, 'currency_code' => 'MMK']);
        MarketplacePayment::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_vendor_order_id' => $vendor->id, 'marketplace_store_id' => $store->id, 'method' => 'cod', 'status' => 'pending', 'amount' => 1500, 'currency_code' => 'MMK', 'idempotency_key' => 'mon-payment-001']);
        MarketplaceShipment::create(['marketplace_vendor_order_id' => $vendor->id, 'tenant_id' => $tenant->id, 'delivery_type' => 'external_courier', 'carrier_name' => 'Control Courier', 'tracking_number' => 'TRACK-MON-001', 'internal_tracking_id' => 'INT-MON-001', 'status' => 'in_transit']);
        MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'MON-002', 'currency_code' => 'MMK', 'status' => 'delivered', 'payment_status' => 'paid', 'grand_total' => 2200, 'delivery_address' => [], 'idempotency_key' => 'mon-002', 'request_hash' => hash('sha256', 'mon-002')]);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/orders?search=TRACK-MON-001&fulfillment_status=active&shipment_status=in_transit&per_page=25&sort=total&direction=desc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.summary.total_orders', 2)
            ->assertJsonPath('meta.summary.processing_orders', 1)
            ->assertJsonPath('meta.summary.payment_pending_orders', 1)
            ->assertJsonPath('data.0.id', $order->uuid)
            ->assertJsonPath('data.0.customer.email', $customer->email)
            ->assertJsonPath('data.0.vendor_count', 1)
            ->assertJsonPath('data.0.vendor_orders.0.vendor_order_number', $vendor->vendor_order_number)
            ->assertJsonPath('data.0.shipment_summary.in_transit', 1);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/orders?store_id='.$store->uuid.'&per_page=25')
            ->assertOk()->assertJsonPath('meta.total', 1);

        [, $businessAdmin] = $this->businessWithAdmin('CONTROL-ORDERS-ADMIN', 'control-orders-admin@example.com');
        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/orders')->assertForbidden();
    }

    public function test_order_monitoring_paginates_a_large_catalog_without_deep_row_payloads(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-ORDERS-LARGE', 'control-orders-large@example.com');
        $customer = User::factory()->create(['email' => 'control-orders-large-customer@example.com']);
        $now = now();
        $orders = [];
        for ($index = 1; $index <= 10000; $index++) {
            $key = 'control-orders-large-'.$index;
            $orders[] = ['uuid' => (string) Str::uuid(), 'customer_user_id' => $customer->id, 'order_number' => 'LARGE-MON-'.str_pad((string) $index, 5, '0', STR_PAD_LEFT), 'currency_code' => 'MMK', 'status' => 'confirmed', 'payment_status' => 'pending', 'subtotal' => 100, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'grand_total' => 100, 'delivery_address' => json_encode([]), 'idempotency_key' => $key, 'request_hash' => hash('sha256', $key), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (array_chunk($orders, 1000) as $chunk) {
            DB::table('marketplace_orders')->insert($chunk);
        }
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/orders?per_page=100&page=100&sort=order_number&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 10000)
            ->assertJsonPath('meta.per_page', 100)
            ->assertJsonPath('meta.current_page', 100)
            ->assertJsonPath('meta.last_page', 100)
            ->assertJsonCount(100, 'data');
    }

    public function test_super_admin_interventions_are_reasoned_audited_and_business_scoped(): void
    {
        [$tenant, $businessAdmin] = $this->businessWithAdmin('CONTROL-INTERVENTION', 'intervention-business@example.com');
        $customer = User::factory()->create(['email' => 'intervention-customer@example.com']);
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Intervention Store', 'slug' => 'intervention-store', 'approval_status' => 'approved', 'is_active' => true]);
        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'INT-001', 'currency_code' => 'MMK', 'status' => 'confirmed', 'payment_status' => 'pending', 'grand_total' => 1500, 'delivery_address' => [], 'idempotency_key' => 'int-001', 'request_hash' => hash('sha256', 'int-001')]);
        MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'vendor_order_number' => 'V-INT-001', 'status' => 'processing', 'total' => 1500, 'currency_code' => 'MMK']);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/orders/'.$order->uuid)->assertForbidden();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/orders/'.$order->uuid)->assertOk()->assertJsonPath('data.customer.email', $customer->email);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/hold', ['action' => 'hold', 'reason' => 'Disputed delivery address'])->assertOk()->assertJsonPath('data.admin_hold', true);
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.order_hold_updated', 'tenant_id' => $tenant->id, 'actor_user_id' => $superAdmin->id]);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/status-correction', ['status' => 'delivered', 'reason' => 'Invalid skipped transition'])->assertConflict();
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/status-correction', ['status' => 'processing', 'reason' => 'Validated fulfillment correction'])->assertOk()->assertJsonPath('data.status', 'processing');
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/investigation-notes', ['note' => 'Customer dispute reviewed'])->assertOk();
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.order_investigation_note_added', 'tenant_id' => $tenant->id]);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/hold', ['action' => 'release', 'reason' => 'Dispute evidence verified'])->assertOk()->assertJsonPath('data.admin_hold', false);
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
