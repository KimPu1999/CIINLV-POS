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
use Illuminate\Support\Facades\Hash;
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

    public function test_reports_and_audit_logs_use_real_marketplace_records(): void
    {
        [$tenant] = $this->businessWithAdmin('CONTROL-D', 'control-d@example.com');
        MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Control D Store', 'slug' => 'control-d-store', 'approval_status' => 'approved', 'is_active' => true]);
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/reports')->assertOk()->assertJsonPath('data.summary.orders', 0);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/audit-logs')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/settings')->assertOk()->assertJsonPath('data.configured', false);
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
