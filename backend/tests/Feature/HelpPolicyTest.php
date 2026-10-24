<?php

namespace Tests\Feature;

use App\Models\FaqEntry;
use App\Models\PolicyDocument;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HelpPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_draft_publish_and_archive_policy_versions(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);

        $first = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/help/policies', [
            'type' => 'terms_conditions',
            'version' => '1.0',
            'locale' => 'en',
            'title' => 'Terms',
            'content' => 'Approved draft content.',
        ])->assertCreated()->json('data');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/help/policies/'.$first['id'].'/publish')->assertOk();

        $second = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/help/policies', [
            'type' => 'terms_conditions',
            'version' => '2.0',
            'locale' => 'en',
            'title' => 'Terms v2',
            'content' => 'New approved draft content.',
        ])->assertCreated()->json('data');
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/help/policies/'.$second['id'].'/publish')->assertOk();

        $this->assertDatabaseHas('policy_documents', ['id' => $first['id'], 'status' => 'archived']);
        $this->assertDatabaseHas('policy_documents', ['id' => $second['id'], 'status' => 'published']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.policy_published', 'subject_id' => (string) $second['id']]);
    }

    public function test_business_admin_can_only_read_published_policy_and_faq_content(): void
    {
        $tenant = Tenant::create(['name' => 'Help Business', 'slug' => 'help-business']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        $admin = User::factory()->create();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active']);
        PolicyDocument::create(['type' => 'seller_terms', 'version' => '1.0', 'locale' => 'en', 'title' => 'Seller terms', 'content' => 'Published', 'status' => 'published', 'published_at' => now()]);
        PolicyDocument::create(['type' => 'seller_terms', 'version' => '2.0', 'locale' => 'en', 'title' => 'Draft seller terms', 'content' => 'Draft', 'status' => 'draft']);
        FaqEntry::create(['category' => 'orders', 'question' => 'How do orders work?', 'answer' => 'Use the order workspace.', 'locale' => 'en', 'status' => 'published', 'display_order' => 1]);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->getJson('/api/v1/business/help/policies?locale=en')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->getJson('/api/v1/business/help/faqs?locale=en')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/platform/help/policies', [])->assertForbidden();
    }
}
