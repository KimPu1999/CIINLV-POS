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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function test_platform_customer_status_filter_is_scoped_inside_customer_profile_relation(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $active = User::factory()->create(['email' => 'active-customer@example.com']);
        $suspended = User::factory()->create(['email' => 'suspended-customer@example.com']);
        CustomerProfile::create(['user_id' => $active->id, 'status' => 'active']);
        CustomerProfile::create(['user_id' => $suspended->id, 'status' => 'suspended']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/customers?status=active')
            ->assertOk()
            ->assertJsonFragment(['email' => $active->email])
            ->assertJsonMissing(['email' => $suspended->email]);
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

    public function test_platform_super_admin_can_manually_verify_an_unverified_customer_with_audited_evidence(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $customer = User::factory()->unverified()->create(['email' => 'manual-verify@example.com']);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/platform/customers/{$customer->id}/email-verification", [
                'reason' => 'Customer confirmed ownership through the approved support callback.',
                'evidence_reference' => 'SUP-2026-0042',
            ])
            ->assertOk()
            ->assertJsonPath('data.email_verification_method', 'admin_override')
            ->assertJsonPath('data.email', $customer->email);

        $this->assertNotNull($customer->refresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.customer_email_verified_manually',
            'actor_user_id' => $admin->id,
            'subject_id' => (string) $customer->id,
        ]);
        $this->assertSame('admin_override', DB::table('audit_logs')->where('action', 'platform.customer_email_verified_manually')->latest('id')->value('metadata->verification_method'));
    }

    public function test_manual_customer_email_verification_requires_reason_and_evidence_and_rejects_non_customers(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $customer = User::factory()->unverified()->create();
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/platform/customers/{$customer->id}/email-verification", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'evidence_reference']);

        $nonCustomer = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/platform/customers/{$nonCustomer->id}/email-verification", [
                'reason' => 'This account is not a customer and must be rejected.',
                'evidence_reference' => 'SUP-2026-0043',
            ])
            ->assertForbidden();
    }

    public function test_business_admin_cannot_manually_verify_customer_email_and_verified_accounts_are_not_reverified(): void
    {
        [$tenant] = [Tenant::create(['name' => 'Verification Business', 'slug' => 'verification-business'])];
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $businessAdmin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $businessAdmin->id, 'role_id' => $role->id, 'status' => 'active']);
        $customer = User::factory()->create(['email_verified_at' => now()]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        $this->actingAs($businessAdmin, 'sanctum')
            ->withHeader('X-Tenant-Id', $tenant->uuid)
            ->patchJson("/api/v1/platform/customers/{$customer->id}/email-verification", [
                'reason' => 'A business administrator must not perform this action.',
                'evidence_reference' => 'SUP-2026-0044',
            ])
            ->assertForbidden();

        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/platform/customers/{$customer->id}/email-verification", [
                'reason' => 'This account has already completed email verification.',
                'evidence_reference' => 'SUP-2026-0045',
            ])
            ->assertStatus(409);
    }

    public function test_platform_listing_returns_filter_metadata_statistics_and_supported_page_sizes(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $verified = User::factory()->create(['name' => 'Verified Buyer', 'email' => 'verified-filter@example.com', 'last_login_at' => now()]);
        $unverified = User::factory()->unverified()->create(['name' => 'Phone Buyer', 'email' => 'phone-filter@example.com', 'last_login_at' => null]);
        CustomerProfile::create(['user_id' => $verified->id, 'phone' => '09999999991', 'status' => 'active']);
        CustomerProfile::create(['user_id' => $unverified->id, 'phone' => '09999999992', 'status' => 'suspended']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/customers?verification=verified&last_login_from='.now()->toDateString().'&per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.statistics.total', 2)
            ->assertJsonPath('meta.statistics.active', 1)
            ->assertJsonPath('meta.statistics.suspended', 1)
            ->assertJsonPath('data.0.email', 'verified-filter@example.com');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/customers?search=09999999992')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'phone-filter@example.com');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/customers?per_page=10')
            ->assertUnprocessable();
    }

    public function test_platform_listing_scales_with_ten_thousand_customers_without_loading_all_rows(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $timestamp = now();
        $password = Hash::make('password');

        foreach (array_chunk(range(1, 10000), 500) as $chunk) {
            $users = array_map(fn (int $number) => [
                'name' => 'Scale Customer '.$number,
                'email' => 'scale-customer-'.$number.'@example.com',
                'email_verified_at' => $timestamp,
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $chunk);
            DB::table('users')->insert($users);
        }

        $customerIds = DB::table('users')->where('email', 'like', 'scale-customer-%@example.com')->pluck('id');
        foreach ($customerIds->chunk(500) as $chunk) {
            $profiles = $chunk->map(fn (int $id) => [
                'user_id' => $id,
                'status' => 'active',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all();
            DB::table('customer_profiles')->insert($profiles);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/customers?page=100&per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 10000)
            ->assertJsonPath('meta.current_page', 100)
            ->assertJsonPath('meta.last_page', 100)
            ->assertJsonCount(100, 'data');
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
