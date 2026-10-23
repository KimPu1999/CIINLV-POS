<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformBusinessProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_platform_super_admin_can_monitor_business_products(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/platform/business-products')->assertForbidden();
    }

    public function test_super_admin_can_filter_paginate_and_open_product_detail_without_mutation(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [$tenantA, $productA] = $this->productFixture('Products Alpha', 'ALPHA', 'Blue Widget', 'ALP-001');
        [, $productB] = $this->productFixture('Products Beta', 'BETA', 'Red Widget', 'BET-001');

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/business-products?business='.$tenantA->uuid.'&search=Blue&per_page=1')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $productA->uuid)->assertJsonPath('data.0.business.name', 'Products Alpha');

        $detail = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/platform/business-products/'.$productA->uuid)->assertOk();
        $detail->assertJsonPath('data.variants.0.sku', 'ALP-001')->assertJsonPath('data.variants.0.inventory.0.available', '8.000');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.business_product_viewed', 'actor_user_id' => $admin->id, 'subject_id' => (string) $productA->id]);
        $this->assertDatabaseHas('products', ['id' => $productB->id, 'name' => 'Red Widget']);
    }

    public function test_export_is_platform_only_and_audited(): void
    {
        $admin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        [, $product] = $this->productFixture('Export Products', 'EXP', 'Export Widget', 'EXP-001');

        $response = $this->actingAs($admin, 'sanctum')->get('/api/v1/platform/business-products/export');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString($product->name, $response->streamedContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.business_products_exported', 'actor_user_id' => $admin->id]);
    }

    private function productFixture(string $name, string $code, string $productName, string $sku): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $user = User::factory()->create();
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => $name.' Branch', 'code' => $code.'-BR', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $user->id]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'location_type' => 'store', 'name' => $store->name.' Stockroom', 'code' => $code.'-STOCK', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => $productName, 'slug' => strtolower($code).'-widget', 'brand' => 'CIINLV', 'status' => 'active', 'track_inventory' => true]);
        $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $sku, 'selling_price' => 125, 'purchase_cost' => 80, 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 10, 'reserved_quantity' => 2, 'average_cost' => 80]);

        return [$tenant, $product];
    }
}
