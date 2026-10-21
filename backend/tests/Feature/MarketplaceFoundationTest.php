<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
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
        ];

        $this->postJson('/api/v1/marketplace/auth/register', $payload)->assertCreated();
        $this->postJson('/api/v1/marketplace/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('customer_profiles', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_customer_registration_does_not_create_business_membership_or_platform_role(): void
    {
        $this->postJson('/api/v1/marketplace/auth/register', [
            'name' => 'Marketplace Customer',
            'email' => 'permissions@example.com',
            'password' => 'CorrectPassword1!',
            'password_confirmation' => 'CorrectPassword1!',
        ])->assertCreated();

        $user = User::query()->where('email', 'permissions@example.com')->firstOrFail();

        $this->assertNull($user->platform_role);
        $this->assertDatabaseMissing('tenant_memberships', ['user_id' => $user->id]);
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $user->id, 'status' => 'active']);
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

        $this->getJson('/api/v1/marketplace/products?search=FILTER-SKU-001&min_price=2000&sort=price-high')
            ->assertOk()
            ->assertJsonCount(1, 'data')
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
