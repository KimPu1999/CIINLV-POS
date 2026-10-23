<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBusinessVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_load_verification_details_and_approval_is_audited(): void
    {
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $owner = User::factory()->create(['name' => 'Applicant', 'email' => 'applicant@example.test']);
        $business = Tenant::query()->create([
            'name' => 'Verification Business', 'slug' => 'verification-business', 'status' => 'pending',
            'approval_status' => 'pending', 'verification_status' => 'pending', 'email' => 'business@example.test', 'email_verified_at' => now(),
        ]);
        $role = Role::query()->create(['tenant_id' => $business->id, 'name' => 'business_admin']);
        $business->memberships()->create(['user_id' => $owner->id, 'role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($superAdmin, 'sanctum')->getJson("/api/v1/platform/businesses/{$business->uuid}/verification")
            ->assertOk()->assertJsonPath('data.business.id', $business->uuid)->assertJsonPath('data.applicant.email', $owner->email);

        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.business_verification_viewed', 'tenant_id' => $business->id]);

        $this->actingAs($superAdmin, 'sanctum')->postJson("/api/v1/platform/businesses/{$business->uuid}/approve")
            ->assertOk()->assertJsonPath('data.verification_status', 'approved');
        $this->assertDatabaseHas('audit_logs', ['action' => 'business.registration_approved', 'tenant_id' => $business->id]);
    }

    public function test_business_admin_cannot_view_platform_verification_details(): void
    {
        $admin = User::factory()->create();
        $business = Tenant::query()->create(['name' => 'Private Business', 'slug' => 'private-business', 'status' => 'pending']);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/platform/businesses/{$business->uuid}/verification")
            ->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'platform.business_verification_viewed', 'tenant_id' => $business->id]);
    }
}
