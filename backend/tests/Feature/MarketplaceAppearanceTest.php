<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketplaceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_draft_and_publish_appearance_without_exposing_draft_publicly(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $appearance = app(MarketplaceSettingsService::class)->appearanceDefaults();
        $appearance['light']['header_bg'] = '#102A56';

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/settings/appearance', ['appearance' => $appearance])
            ->assertOk()
            ->assertJsonPath('data.draft.light.header_bg', '#102A56');

        $this->getJson('/api/v1/marketplace/appearance')
            ->assertOk()
            ->assertJsonPath('data.appearance.light.header_bg', '#003868');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/marketplace/settings/appearance/publish')
            ->assertOk()
            ->assertJsonPath('data.published.light.header_bg', '#102A56');

        $this->getJson('/api/v1/marketplace/appearance')
            ->assertOk()
            ->assertJsonPath('data.appearance.light.header_bg', '#102A56');
    }

    public function test_footer_social_links_are_drafted_and_only_published_links_are_public(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $appearance = app(MarketplaceSettingsService::class)->appearanceDefaults();
        $appearance['footer'] = array_merge($appearance['footer'], [
            'show_social_icons' => true,
            'facebook_url' => 'https://facebook.com/ciinlv',
            'description_en' => 'Draft footer',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/settings/appearance', ['appearance' => $appearance])
            ->assertOk()
            ->assertJsonPath('data.draft.footer.facebook_url', 'https://facebook.com/ciinlv');

        $this->getJson('/api/v1/marketplace/appearance')
            ->assertOk()
            ->assertJsonPath('data.appearance.footer.facebook_url', null)
            ->assertJsonPath('data.appearance.footer.show_social_icons', false);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/marketplace/settings/appearance/publish')
            ->assertOk()
            ->assertJsonPath('data.published.footer.facebook_url', 'https://facebook.com/ciinlv');
    }

    public function test_footer_social_links_reject_unsafe_or_unapproved_urls(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $appearance = app(MarketplaceSettingsService::class)->appearanceDefaults();
        $appearance['footer']['facebook_url'] = 'javascript:alert(1)';
        $appearance['footer']['instagram_url'] = 'https://example.com/profile';

        $this->actingAs($admin, 'sanctum')
            ->patchJson('/api/v1/admin/marketplace/settings/appearance', ['appearance' => $appearance])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appearance.footer.facebook_url', 'appearance.footer.instagram_url']);
    }

    public function test_business_admin_cannot_access_global_appearance_management(): void
    {
        $businessAdmin = User::factory()->create();

        $this->actingAs($businessAdmin, 'sanctum')
            ->getJson('/api/v1/admin/marketplace/settings/appearance')
            ->assertForbidden();
    }
}
