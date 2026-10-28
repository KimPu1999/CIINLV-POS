<?php

namespace Tests\Feature;

use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceContactInquiryMessage;
use App\Models\MarketplaceStore;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceContactInquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_view_and_manage_a_tenant_scoped_inquiry(): void
    {
        [$tenantA, $adminA, $storeA] = $this->business('SUPPORT-A', 'support-a@example.com');
        [$tenantB, $adminB, $storeB] = $this->business('SUPPORT-B', 'support-b@example.com');

        $this->postJson('/api/v1/marketplace/contact', [
            'name' => 'Customer A', 'email' => 'customer-a@example.com', 'phone' => '0900000000',
            'subject' => 'Order support', 'message' => 'Please help with my marketplace order.', 'store' => $storeA->slug,
        ])->assertCreated()->assertJsonPath('data.email_delivery_status', 'not_configured');

        $inquiry = MarketplaceContactInquiry::query()->where('tenant_id', $tenantA->id)->firstOrFail();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/vendor/marketplace/contact-inquiries')
            ->assertOk()->assertJsonPath('data.0.customer_name', 'Customer A')->assertJsonPath('unread_count', 1);

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/vendor/marketplace/contact-inquiries')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/vendor/marketplace/contact-inquiries/'.$inquiry->id)->assertNotFound();

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->getJson('/api/v1/vendor/marketplace/contact-inquiries/'.$inquiry->id)
            ->assertOk()->assertJsonPath('data.message', 'Please help with my marketplace order.');

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->patchJson('/api/v1/vendor/marketplace/contact-inquiries/'.$inquiry->id.'/status', ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->postJson('/api/v1/vendor/marketplace/contact-inquiries/'.$inquiry->id.'/notes', ['message' => 'Checked the order timeline.'])
            ->assertOk();

        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)
            ->postJson('/api/v1/vendor/marketplace/contact-inquiries/'.$inquiry->id.'/replies', ['message' => 'We are reviewing this for you.'])
            ->assertOk()->assertJsonPath('data.messages.1.delivery_status', 'not_configured');

        $this->assertDatabaseHas('marketplace_contact_inquiry_messages', [
            'marketplace_contact_inquiry_id' => $inquiry->id,
            'message_type' => MarketplaceContactInquiryMessage::TYPE_INTERNAL_NOTE,
        ]);
    }

    private function business(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => $code.' Store', 'slug' => strtolower($code).'-store', 'approval_status' => 'approved', 'is_active' => true]);

        return [$tenant, $admin, $store];
    }
}
