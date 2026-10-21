<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderCommission;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\MarketplaceCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceSettingsCommissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_super_admin_can_persist_marketplace_settings_and_audit_the_change(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/settings')
            ->assertOk()->assertJsonPath('data.configured', true)->assertJsonPath('data.settings.enabled', true);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/marketplace/settings', [
            'enabled' => false,
            'seller_registration_enabled' => false,
            'default_commission_rate' => '12.5000',
            'revision' => 1,
        ])->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.default_commission_rate', '12.5000');

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/settings')->assertJsonPath('data.settings.enabled', false);
        $this->assertDatabaseHas('marketplace_settings', ['enabled' => false, 'seller_registration_enabled' => false]);
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.settings_updated', 'actor_user_id' => $admin->id]);
    }

    public function test_non_platform_users_cannot_modify_marketplace_settings(): void
    {
        [, $businessAdmin] = $this->businessWithAdmin('SETTINGS-A', 'settings-a@example.com');

        $this->actingAs($businessAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/settings', ['enabled' => false])->assertForbidden();
        $this->getJson('/api/v1/admin/marketplace/settings')->assertUnauthorized();
    }

    public function test_seller_override_is_effective_and_preview_uses_exact_decimal_amounts(): void
    {
        [$tenant] = $this->businessWithAdmin('SETTINGS-B', 'settings-b@example.com');
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/marketplace/settings', ['default_commission_rate' => '10.0000', 'revision' => 1])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', ['tenant_id' => $tenant->id, 'rate' => '15.0000'])->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-preview?tenant_id='.$tenant->id.'&base_amount=1000.00')
            ->assertOk()->assertJsonPath('data.rate', '15.0000')->assertJsonPath('data.commission_amount', '150.00')->assertJsonPath('data.seller_net_amount', '850.00');
        $this->assertDatabaseCount('marketplace_commission_rules', 2);
    }

    public function test_listing_submitted_as_published_stays_draft_when_listing_approval_is_required(): void
    {
        [$tenant, $seller] = $this->businessWithAdmin('SETTINGS-C', 'settings-c@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Settings C', 'slug' => 'settings-c', 'approval_status' => 'approved', 'is_active' => true]);
        $product = $this->publicProduct($tenant, 'Approval Product');

        $this->actingAs($seller, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/listings', ['product_id' => $product->uuid, 'status' => 'published'])
            ->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.listing_created', 'tenant_id' => $tenant->id]);
    }

    public function test_order_commission_snapshot_and_ledger_are_idempotent(): void
    {
        [$tenant] = $this->businessWithAdmin('SETTINGS-D', 'settings-d@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Settings D', 'slug' => 'settings-d', 'approval_status' => 'approved', 'is_active' => true]);
        $customer = User::factory()->create();
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/marketplace/settings', ['default_commission_rate' => '10.0000', 'revision' => 1])->assertOk();

        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'MKT-SETTINGS-D', 'currency_code' => 'MMK', 'status' => 'confirmed', 'payment_status' => 'pending', 'subtotal' => '1000.00', 'discount_total' => '0.00', 'shipping_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '1000.00', 'delivery_address' => ['city' => 'Yangon'], 'idempotency_key' => 'settings-d-order', 'request_hash' => hash('sha256', 'settings-d')]);
        $vendorOrder = MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'vendor_order_number' => 'VND-SETTINGS-D', 'status' => 'pending', 'subtotal' => '1000.00', 'discount_total' => '0.00', 'shipping_total' => '0.00', 'tax_total' => '0.00', 'total' => '1000.00', 'currency_code' => 'MMK']);

        $service = app(MarketplaceCommissionService::class);
        $first = $service->snapshotForVendorOrder($order, $vendorOrder);
        $second = $service->snapshotForVendorOrder($order, $vendorOrder);

        $this->assertSame($first?->id, $second?->id);
        $this->assertDatabaseCount('marketplace_order_commissions', 1);
        $this->assertDatabaseCount('marketplace_settlement_entries', 2);
        $this->assertDatabaseHas('marketplace_order_commissions', ['commission_amount' => '100.00', 'seller_net_amount' => '900.00', 'status' => 'pending']);
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
