<?php

namespace Tests\Feature;

use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceStore;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_submission_is_validated_and_stored_as_pending(): void
    {
        $tenant = Tenant::create(['name' => 'Contact Business', 'slug' => 'contact-business', 'business_code' => 'CONTACT', 'status' => 'active', 'subscription_status' => 'active']);
        MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => 'Contact Store', 'slug' => 'contact-store', 'approval_status' => 'approved', 'is_active' => true]);

        $this->postJson('/api/v1/marketplace/contact', [
            'name' => 'Marketplace Customer',
            'email' => 'customer@example.com',
            'phone' => '09954060717',
            'subject' => 'order',
            'message' => 'Please help me understand the status of my order.',
            'store' => 'contact-store',
        ])->assertCreated()->assertJsonPath('data.accepted', true);

        $this->assertDatabaseHas('marketplace_contact_inquiries', [
            'email' => 'customer@example.com',
            'subject' => 'order',
            'status' => 'pending',
        ]);
    }

    public function test_public_contact_submission_rejects_invalid_input(): void
    {
        $this->postJson('/api/v1/marketplace/contact', [
            'name' => '',
            'email' => 'not-an-email',
            'subject' => 'order',
            'message' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->assertDatabaseCount((new MarketplaceContactInquiry)->getTable(), 0);
    }
}
