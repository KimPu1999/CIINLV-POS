<?php

namespace Tests\Feature;

use App\Jobs\SendBusinessEmailVerification;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\BusinessEmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BusinessEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_pending_business_and_sends_verification_email(): void
    {
        Notification::fake();
        $response = $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Acme Retail', 'business_code' => 'ACME_RETAIL', 'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123',
        ]);
        $response->assertCreated()->assertJsonPath('data.verification_required', true);
        $this->assertDatabaseHas('tenants', ['business_code' => 'ACME_RETAIL', 'status' => 'pending']);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->assertNull($owner->email_verified_at);
        $this->assertSame(User::STATUS_INVITED, $owner->status);
        Notification::assertSentTo($owner, BusinessEmailVerificationNotification::class);
    }

    public function test_signed_verification_activates_owner_membership_and_business(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Acme Retail', 'business_code' => 'ACME_RETAIL', 'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123',
        ]);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $owner->id, 'hash' => sha1($owner->getEmailForVerification())]);
        $this->get($url)->assertRedirectContains('/email-verification/success');
        $this->assertNotNull($owner->refresh()->email_verified_at);
        $this->assertSame(User::STATUS_ACTIVE, $owner->status);
        $this->assertDatabaseHas('tenants', ['business_code' => 'ACME_RETAIL', 'status' => 'active']);
        $this->assertDatabaseHas('tenant_memberships', ['user_id' => $owner->id, 'status' => 'active']);
    }

    public function test_verification_link_activates_only_the_signed_business_membership(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'First Retail', 'business_code' => 'FIRST_RETAIL', 'owner_name' => 'Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123',
        ]);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $first = $owner->memberships()->firstOrFail()->tenant;
        $second = Tenant::create(['name' => 'Second Retail', 'slug' => 'second-retail', 'business_code' => 'SECOND_RETAIL', 'status' => 'pending']);
        $role = Role::firstOrCreate(['tenant_id' => $second->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $second->id, 'user_id' => $owner->id, 'role_id' => $role->id, 'status' => 'pending']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $owner->id, 'hash' => sha1($owner->getEmailForVerification()), 'tenant_id' => $second->id,
        ]);

        $this->get($url)->assertRedirectContains('/email-verification/success');
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $second->id, 'user_id' => $owner->id, 'status' => 'active']);
        $this->assertDatabaseHas('tenant_memberships', ['tenant_id' => $first->id, 'user_id' => $owner->id, 'status' => 'pending']);
    }

    public function test_expired_link_is_rejected_without_mutating_the_owner(): void
    {
        Notification::fake();
        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Acme Retail', 'business_code' => 'ACME_RETAIL', 'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123',
        ]);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $owner->id, 'hash' => sha1($owner->getEmailForVerification())]);
        $this->get($url)->assertRedirectContains('/email-verification/invalid');
        $this->assertNull($owner->refresh()->email_verified_at);
    }

    public function test_resend_response_does_not_enumerate_accounts(): void
    {
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'missing@example.test'])->assertOk()->assertJsonPath('success', true);
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'owner@example.test'])->assertOk()->assertJsonPath('success', true);
    }

    public function test_duplicate_owner_email_returns_a_field_specific_error(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);
        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Acme Retail', 'business_code' => 'ACME_RETAIL', 'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123',
        ])->assertStatus(422)->assertJsonPath('errors.owner_email.0', 'This owner email is already registered. Use the existing account or choose another email.');
    }

    public function test_registration_queues_verification_delivery_after_database_commit(): void
    {
        Queue::fake();
        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Acme Retail', 'business_code' => 'ACME_RETAIL', 'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Strong-password-123', 'password_confirmation' => 'Strong-password-123',
        ])->assertCreated()->assertJsonPath('data.email_delivery', 'queued');
        Queue::assertPushed(SendBusinessEmailVerification::class);
    }

    public function test_invalid_registration_password_is_rejected_without_creating_records(): void
    {
        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Acme Retail', 'business_code' => 'ACME_RETAIL', 'owner_name' => 'Acme Owner',
            'owner_email' => 'owner@example.test', 'password' => 'Aa1!aaa', 'password_confirmation' => 'Aa1!aaa',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        $this->assertDatabaseMissing('tenants', ['business_code' => 'ACME_RETAIL']);
    }

    public function test_registration_accepts_an_eight_character_password(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/business-registration', [
            'business_name' => 'Eight Character Retail', 'business_code' => 'EIGHT_RETAIL', 'owner_name' => 'Owner',
            'owner_email' => 'eight@example.test', 'password' => 'Aa1!aaaa', 'password_confirmation' => 'Aa1!aaaa',
        ])->assertCreated();
    }
}
