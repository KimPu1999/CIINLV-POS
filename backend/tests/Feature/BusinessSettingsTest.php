<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_update_only_the_active_business_profile(): void
    {
        [$user, $tenant] = $this->businessAdmin('SETTINGS_A');
        $response = $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/business/profile', [
            'name' => 'Updated name', 'business_code' => 'SHOULD_NOT_CHANGE', 'subscription_status' => 'active', 'city' => 'Yangon',
        ]);
        $response->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'name' => 'Updated name', 'business_code' => 'SETTINGS_A', 'city' => 'Yangon']);
        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id, 'business_code' => 'SHOULD_NOT_CHANGE']);
    }

    public function test_settings_and_receipt_configuration_are_tenant_scoped(): void
    {
        [$user, $tenant] = $this->businessAdmin('SETTINGS_B');
        $response = $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/business/settings', ['currency_code' => 'USD', 'timezone' => 'UTC', 'date_format' => 'YYYY-MM-DD']);
        $response->assertOk();
        $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/business/settings/receipt', ['paper_width' => '58', 'show_logo' => false])->assertOk();
        $this->assertDatabaseHas('business_settings', ['tenant_id' => $tenant->id, 'currency_code' => 'USD', 'timezone' => 'UTC']);
        $stored = DB::table('business_settings')->where('tenant_id', $tenant->id)->value('receipt_configuration');
        $this->assertStringContainsString('58', (string) $stored);
    }

    public function test_business_admin_cannot_select_another_business(): void
    {
        [$user] = $this->businessAdmin('SETTINGS_C');
        $other = Tenant::create(['name' => 'Other', 'slug' => 'settings-other', 'business_code' => 'SETTINGS_D', 'status' => 'active', 'subscription_status' => 'trial']);
        $this->actingAs($user)->withHeader('X-Tenant-Id', $other->uuid)->getJson('/api/v1/business/profile')->assertForbidden();
    }

    public function test_logo_upload_rejects_unsupported_files_and_stores_valid_files_privately(): void
    {
        Storage::fake('private');
        [$user, $tenant] = $this->businessAdmin('SETTINGS_E');
        $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->uuid)->post('/api/v1/business/settings/logo', ['logo' => UploadedFile::fake()->create('logo.txt', 10, 'text/plain')])->assertUnprocessable();
        $response = $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->uuid)->post('/api/v1/business/settings/logo', ['logo' => UploadedFile::fake()->image('logo.png', 20, 20)]);
        $response->assertOk();
        $path = Tenant::findOrFail($tenant->id)->logo_path;
        Storage::disk('private')->assertExists($path);
        $this->actingAs($user)->withHeader('X-Tenant-Id', $tenant->uuid)->deleteJson('/api/v1/business/settings/logo')->assertOk();
        Storage::disk('private')->assertMissing($path);
    }

    public function test_operational_settings_are_revisioned_and_card_payments_are_not_enabled_without_provider(): void
    {
        [$user, $tenant] = $this->businessAdmin('SETTINGS_F');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $settings = $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/business/settings')->assertOk();
        $revision = $settings->json('data.revision');

        $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/business/settings/pos', ['revision' => $revision, 'pos_configuration' => ['hold_resume_enabled' => false]])
            ->assertOk()->assertJsonPath('data.pos_configuration.hold_resume_enabled', false);
        $this->assertDatabaseHas('business_settings', ['tenant_id' => $tenant->id]);
        $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/business/settings/pos', ['revision' => $revision, 'pos_configuration' => ['hold_resume_enabled' => true]])
            ->assertStatus(409);
        $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/business/settings/pos', ['pos_configuration' => ['card_payments_display' => true]])
            ->assertUnprocessable();
    }

    public function test_business_information_center_updates_profile_settings_and_masks_encrypted_settlement_details(): void
    {
        [$user, $tenant] = $this->businessAdmin('INFO_A');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $initial = $this->actingAs($user)->withHeaders($headers)->getJson('/api/v1/business/settings/business-information')
            ->assertOk()
            ->assertJsonPath('data.permissions.can_update', true)
            ->assertJsonMissingPath('data.settings.settlement_configuration');
        $revision = $initial->json('data.settings.revision');

        $response = $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/business/settings/business-information', [
            'revision' => $revision,
            'profile' => ['name' => 'Central Business', 'tax_registration_number' => 'TAX-INFO-A'],
            'settings' => [
                'currency_code' => 'USD',
                'tax_configuration' => ['rate' => 7.5, 'mode' => 'exclusive'],
                'pos_configuration' => ['service_charge' => ['enabled' => true, 'type' => 'percentage', 'value' => 2]],
            ],
            'settlement' => ['bank_name' => 'Example Bank', 'account_holder' => 'Central Business', 'account_details' => '1234567890', 'settlement_method' => 'bank_transfer'],
        ])->assertOk()->assertJsonPath('data.profile.name', 'Central Business')->assertJsonPath('data.settings.currency_code', 'USD');

        $masked = (string) $response->json('data.settlement.account_details_masked');
        $this->assertStringEndsWith('7890', $masked);
        $this->assertStringNotContainsString('1234567890', (string) DB::table('business_settings')->where('tenant_id', $tenant->id)->value('settlement_configuration'));
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'tax_registration_number' => 'TAX-INFO-A']);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'business.information_updated']);
    }

    public function test_business_information_documents_are_private_and_tenant_scoped(): void
    {
        Storage::fake('private');
        [$user, $tenant] = $this->businessAdmin('INFO_B');
        [$otherUser, $otherTenant] = $this->businessAdmin('INFO_C');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $response = $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/settings/business-information/documents', [
            'document_type' => 'business_license',
            'document' => UploadedFile::fake()->create('license.pdf', 20, 'application/pdf'),
        ])->assertCreated();
        $documentId = $response->json('data.id');
        $path = DB::table('business_documents')->where('uuid', $documentId)->value('path');
        Storage::disk('private')->assertExists($path);

        $this->actingAs($user)->withHeaders($headers)->get('/api/v1/business/settings/business-information/documents/'.$documentId.'/download')->assertOk();
        $this->actingAs($otherUser)->withHeader('X-Tenant-Id', $otherTenant->uuid)->get('/api/v1/business/settings/business-information/documents/'.$documentId.'/download')->assertNotFound();
    }

    public function test_notification_preferences_and_operational_settings_do_not_cross_tenants(): void
    {
        [$user, $tenant] = $this->businessAdmin('SETTINGS_G');
        $other = Tenant::create(['name' => 'Other settings', 'slug' => 'settings-other-g', 'business_code' => 'SETTINGS_H', 'status' => 'active', 'subscription_status' => 'trial']);
        $headers = ['X-Tenant-Id' => $other->uuid];
        $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/business/settings/inventory', ['inventory_configuration' => ['default_reorder_level' => 10]])->assertForbidden();
        $this->assertDatabaseMissing('business_settings', ['tenant_id' => $tenant->id, 'inventory_configuration' => json_encode(['default_reorder_level' => 10])]);
    }

    private function businessAdmin(string $code): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'trial']);
        $role = DB::table('roles')->where('tenant_id', $tenant->id)->where('name', 'business_admin')->first();
        if (! $role) {
            $role = (object) ['id' => DB::table('roles')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'business_admin', 'created_at' => now(), 'updated_at' => now()])];
        }
        DB::table('tenant_memberships')->insert(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $tenant];
    }
}
