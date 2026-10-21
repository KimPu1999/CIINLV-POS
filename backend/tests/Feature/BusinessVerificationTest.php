<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_update_identity_and_replace_documents(): void
    {
        Storage::fake('private');
        [$user, $tenant] = $this->businessAdmin('VERIFY_A');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($user)->withHeaders($headers)->patchJson('/api/v1/business/profile', [
            'business_type' => 'company', 'id_type' => 'company_registration', 'id_number' => 'ID-1', 'company_registration_number' => 'COMP-1',
        ])->assertOk();
        $response = $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/verification/document', [
            'document_type' => 'id_front_image', 'document' => UploadedFile::fake()->image('front.png'),
        ])->assertOk();
        $path = Tenant::findOrFail($tenant->id)->id_front_image_path;
        Storage::disk('private')->assertExists($path);
        $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/verification/document', [
            'document_type' => 'id_front_image', 'document' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertOk();
        Storage::disk('private')->assertMissing($path);
        $this->assertSame('pending', $response->json('data.verification_status'));
    }

    public function test_invalid_upload_is_rejected_and_other_tenant_cannot_read_document(): void
    {
        Storage::fake('private');
        [$user, $tenant] = $this->businessAdmin('VERIFY_B');
        [$otherUser, $otherTenant] = $this->businessAdmin('VERIFY_C');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/verification/document', [
            'document_type' => 'id_front_image', 'document' => UploadedFile::fake()->create('document.pdf', 20, 'application/pdf'),
        ])->assertUnprocessable();
        $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/verification/document', [
            'document_type' => 'id_front_image', 'document' => UploadedFile::fake()->image('front.png'),
        ])->assertOk();
        $this->actingAs($otherUser)->withHeader('X-Tenant-Id', $otherTenant->uuid)->get('/api/v1/business/verification/document/id_front_image')->assertNotFound();
    }

    public function test_profile_and_logo_media_are_private_and_tenant_scoped(): void
    {
        Storage::fake('private');
        [$user, $tenant] = $this->businessAdmin('MEDIA_A');
        [$otherUser, $otherTenant] = $this->businessAdmin('MEDIA_B');
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/verification/document', [
            'document_type' => 'admin_profile_image', 'document' => UploadedFile::fake()->image('avatar.webp'),
        ])->assertOk();
        $this->actingAs($user)->get('/api/v1/me/profile-image')->assertOk();
        $this->actingAs($otherUser)->get('/api/v1/me/profile-image')->assertNotFound();

        $this->actingAs($user)->withHeaders($headers)->post('/api/v1/business/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();
        $this->actingAs($user)->get('/api/v1/me/business-logo/'.$tenant->uuid)->assertOk();
        $this->actingAs($otherUser)->get('/api/v1/me/business-logo/'.$tenant->uuid)->assertForbidden();
        $this->actingAs($user)->withHeaders($headers)->delete('/api/v1/business/verification/document/admin_profile_image')->assertOk();
        $this->actingAs($user)->get('/api/v1/me/profile-image')->assertNotFound();

        $this->assertNotSame($tenant->uuid, $otherTenant->uuid);
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
