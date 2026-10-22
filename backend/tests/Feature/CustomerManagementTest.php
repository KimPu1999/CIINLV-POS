<?php

namespace Tests\Feature;

use App\Models\CustomerProfile;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Role;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_manage_customer_without_exposing_password_or_tokens(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $customer = User::factory()->create(['name' => 'Marketplace Buyer', 'email' => 'buyer@example.com']);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active', 'terms_accepted_at' => now(), 'terms_version' => 'v1']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/customers?search=buyer')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'buyer@example.com')
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/platform/customers/{$customer->id}/status", ['status' => 'suspended'])
            ->assertOk();

        $this->assertDatabaseHas('customer_profiles', ['user_id' => $customer->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('users', ['id' => $customer->id, 'status' => User::STATUS_SUSPENDED]);
    }

    public function test_business_admin_only_sees_customers_with_orders_for_selected_tenant(): void
    {
        [$tenantA, $tenantB] = [
            Tenant::create(['name' => 'Business A', 'slug' => 'business-a']),
            Tenant::create(['name' => 'Business B', 'slug' => 'business-b']),
        ];
        $role = Role::create(['tenant_id' => $tenantA->id, 'name' => 'business_admin']);
        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenantA->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active']);
        $visible = $this->customerWithOrder($tenantA);
        $hidden = $this->customerWithOrder($tenantB);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/business/customers')
            ->assertOk()
            ->assertJsonFragment(['email' => $visible->email])
            ->assertJsonMissing(['email' => $hidden->email]);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson("/api/v1/business/customers/{$hidden->id}")
            ->assertForbidden();
    }

    public function test_business_admin_cannot_use_platform_customer_status_endpoint(): void
    {
        $admin = User::factory()->create();
        $customer = User::factory()->create();
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/platform/customers/{$customer->id}/status", ['status' => 'suspended'])
            ->assertForbidden();
    }

    private function customerWithOrder(Tenant $tenant): User
    {
        $customer = User::factory()->create();
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => $tenant->name.' Store', 'code' => 'STORE-'.$tenant->id]);
        $marketplaceStore = MarketplaceStore::create(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $tenant->name.' Marketplace', 'slug' => 'marketplace-'.$tenant->id, 'approval_status' => 'approved', 'is_active' => true]);
        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'ORD-'.$tenant->id.'-'.Str::random(6), 'currency_code' => 'MMK', 'status' => 'confirmed', 'payment_status' => 'pending', 'delivery_address' => [], 'idempotency_key' => Str::uuid()->toString(), 'request_hash' => hash('sha256', Str::random())]);
        MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $marketplaceStore->id, 'vendor_order_number' => 'V-'.$tenant->id.'-'.Str::random(6), 'status' => 'pending', 'currency_code' => 'MMK']);
        $store->refresh();

        return $customer;
    }
}
