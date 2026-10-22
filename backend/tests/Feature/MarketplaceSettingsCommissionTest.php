<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceOrder;
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
        $this->getJson('/api/v1/admin/marketplace/settings')->assertForbidden();
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

    public function test_platform_admin_can_search_businesses_and_apply_different_rates_with_global_fallback(): void
    {
        [$businessA] = $this->businessWithAdmin('COMMISSION-A', 'commission-a@example.com');
        [$businessB] = $this->businessWithAdmin('COMMISSION-B', 'commission-b@example.com');
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/marketplace/settings', ['default_commission_rate' => '10.0000', 'revision' => 1])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', ['tenant_id' => $businessA->id, 'rate' => '5.0000'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', ['tenant_id' => $businessB->id, 'rate' => '15.0000'])->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-businesses?search=COMMISSION-A')
            ->assertOk()
            ->assertJsonPath('data.0.business_id', 'COMMISSION-A')
            ->assertJsonPath('data.0.effective_commission', '5.0000')
            ->assertJsonPath('data.0.rule_type', 'business_override');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-preview?tenant_id='.$businessB->id.'&base_amount=1000.00')
            ->assertOk()->assertJsonPath('data.rate', '15.0000');

        $ruleId = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-rules?tenant_id='.$businessA->id)
            ->assertOk()->json('data.0.id');
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/admin/marketplace/commission-rules/'.$ruleId)
            ->assertOk()->assertJsonPath('message', 'Business override removed. The global default now applies.');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-preview?tenant_id='.$businessA->id.'&base_amount=1000.00')
            ->assertOk()->assertJsonPath('data.rate', '10.0000');
        $this->assertDatabaseHas('marketplace_audit_logs', ['action' => 'marketplace.commission_rule_removed', 'actor_user_id' => $admin->id]);
    }

    public function test_promotional_rule_has_priority_then_category_rule_after_expiration(): void
    {
        [$tenant] = $this->businessWithAdmin('COMMISSION-PRIORITY', 'commission-priority@example.com');
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'Priority', 'slug' => 'commission-priority-category', 'status' => 'active']);
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/marketplace/settings', ['default_commission_rate' => '10.0000', 'revision' => 1])->assertOk();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', [
            'rule_type' => 'category',
            'category_id' => $category->id,
            'rate' => '20.0000',
        ])->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', [
            'rule_type' => 'promotional',
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'rate' => '5.0000',
            'effective_until' => now()->addDay()->toIso8601String(),
        ])->assertCreated();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-preview?tenant_id='.$tenant->id.'&category_id='.$category->id.'&base_amount=1000.00')
            ->assertOk()->assertJsonPath('data.rate', '5.0000');
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-preview?tenant_id='.$tenant->id.'&category_id='.$category->id.'&base_amount=1000.00&at='.urlencode(now()->addDays(2)->toIso8601String()))
            ->assertOk()->assertJsonPath('data.rate', '20.0000');
    }

    public function test_business_admin_cannot_read_or_change_platform_commission_rules(): void
    {
        [$tenant, $businessAdmin] = $this->businessWithAdmin('COMMISSION-C', 'commission-c@example.com');

        $this->actingAs($businessAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/commission-businesses')->assertForbidden();
        $this->actingAs($businessAdmin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', ['tenant_id' => $tenant->id, 'rate' => '25.0000'])->assertForbidden();
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
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', ['tenant_id' => $tenant->id, 'rate' => '5.0000'])->assertCreated();

        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'MKT-SETTINGS-D', 'currency_code' => 'MMK', 'status' => 'confirmed', 'payment_status' => 'pending', 'subtotal' => '1000.00', 'discount_total' => '100.00', 'shipping_total' => '0.00', 'tax_total' => '0.00', 'grand_total' => '900.00', 'delivery_address' => ['city' => 'Yangon'], 'idempotency_key' => 'settings-d-order', 'request_hash' => hash('sha256', 'settings-d')]);
        $vendorOrder = MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'vendor_order_number' => 'VND-SETTINGS-D', 'status' => 'pending', 'subtotal' => '1000.00', 'discount_total' => '100.00', 'shipping_total' => '0.00', 'tax_total' => '0.00', 'total' => '900.00', 'currency_code' => 'MMK']);

        $service = app(MarketplaceCommissionService::class);
        $first = $service->snapshotForVendorOrder($order, $vendorOrder);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/commission-rules', ['tenant_id' => $tenant->id, 'rate' => '15.0000'])->assertCreated();
        $second = $service->snapshotForVendorOrder($order, $vendorOrder);

        $this->assertSame($first?->id, $second?->id);
        $this->assertDatabaseCount('marketplace_order_commissions', 1);
        $this->assertDatabaseCount('marketplace_settlement_entries', 2);
        $this->assertDatabaseHas('marketplace_order_commissions', ['commission_base_amount' => '900.00', 'commission_amount' => '45.00', 'commission_rate' => '5.0000', 'seller_net_amount' => '855.00', 'status' => 'pending']);

        $service->markOrderPayable($order);
        $payout = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/marketplace/payouts', [
            'tenant_id' => $tenant->id,
            'currency_code' => 'MMK',
            'amount' => '855.00',
            'idempotency_key' => 'settings-d-payout',
            'reason' => 'Test payout approval',
        ])->assertCreated()->json('data.uuid');
        $this->actingAs($admin, 'sanctum')->patchJson('/api/v1/admin/marketplace/payouts/'.$payout.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('marketplace_payouts', ['uuid' => $payout, 'status' => 'approved', 'amount' => '855.00']);
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
