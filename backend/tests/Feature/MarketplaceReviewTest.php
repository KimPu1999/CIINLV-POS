<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerProfile;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceStore;
use App\Models\MarketplaceVendorOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_review_only_a_delivered_purchased_item_and_cannot_duplicate_it(): void
    {
        [$tenant, $admin, $listing, $item, $customer] = $this->deliveredPurchase('REVIEW-A');

        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/marketplace/reviews/eligible')->assertOk()->assertJsonPath('data.0.id', $item->uuid);
        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/reviews', ['order_item_id' => $item->uuid, 'rating' => 5, 'title' => '<b>Great</b>', 'comment' => '<script>alert(1)</script>Fast delivery']);
        $response->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.comment', 'alert(1)Fast delivery');
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/reviews', ['order_item_id' => $item->uuid, 'rating' => 4, 'comment' => 'Again'])->assertUnprocessable();
        $this->assertDatabaseHas('marketplace_reviews', ['tenant_id' => $tenant->id, 'customer_user_id' => $customer->id, 'status' => 'pending']);
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->getJson('/api/v1/vendor/marketplace/reviews')->assertOk()->assertJsonPath('meta.summary.pending', 1);
    }

    public function test_only_platform_moderation_changes_public_visibility_and_businesses_are_isolated(): void
    {
        [$tenantA, $adminA, $listingA, $itemA, $customer] = $this->deliveredPurchase('REVIEW-B');
        [$tenantB, $adminB] = $this->business('REVIEW-C', 'review-c@example.com');
        $review = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/reviews', ['order_item_id' => $itemA->uuid, 'rating' => 2, 'comment' => 'Needs improvement'])->assertCreated()->json('data.id');

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/vendor/marketplace/reviews')->assertOk()->assertJsonPath('meta.summary.total', 0);
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->patchJson('/api/v1/admin/marketplace/reviews/'.$review.'/moderation', ['status' => 'approved'])->assertForbidden();

        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/reviews/'.$review.'/moderation', ['status' => 'approved', 'reason' => 'Verified purchase'])->assertOk();
        $this->getJson('/api/v1/marketplace/products/'.$listingA->slug.'/reviews')->assertOk()->assertJsonPath('meta.summary.total_reviews', 1)->assertJsonPath('meta.summary.average_rating', '2.00');
        $this->assertDatabaseHas('marketplace_review_moderation_histories', ['new_status' => 'approved', 'moderator_user_id' => $superAdmin->id]);
    }

    private function deliveredPurchase(string $code): array
    {
        [$tenant, $admin] = $this->business($code, strtolower($code).'@example.com');
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => $code, 'slug' => strtolower($code), 'approval_status' => 'approved', 'is_active' => true]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => $code.' Category', 'slug' => strtolower($code).'-category', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => $code.' Product', 'slug' => strtolower($code).'-product', 'status' => 'active', 'track_inventory' => false, 'tax_exempt' => false]);
        ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $code.'-SKU', 'selling_price' => 1000, 'status' => 'active']);
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => strtolower($code).'-listing', 'status' => 'published', 'published_at' => now()]);
        $customer = User::factory()->create(['email' => strtolower($code).'-customer@example.com']);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);
        $order = MarketplaceOrder::create(['customer_user_id' => $customer->id, 'order_number' => 'ORD-'.$code, 'currency_code' => 'MMK', 'status' => 'delivered', 'payment_status' => 'paid', 'subtotal' => 1000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'grand_total' => 1000, 'delivery_address' => ['city' => 'Yangon'], 'idempotency_key' => 'key-'.$code, 'request_hash' => hash('sha256', $code)]);
        $vendorOrder = MarketplaceVendorOrder::create(['marketplace_order_id' => $order->id, 'tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'vendor_order_number' => 'VND-'.$code, 'status' => 'delivered', 'subtotal' => 1000, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'total' => 1000, 'currency_code' => 'MMK']);
        $item = MarketplaceOrderItem::create(['marketplace_order_id' => $order->id, 'marketplace_vendor_order_id' => $vendorOrder->id, 'tenant_id' => $tenant->id, 'marketplace_listing_id' => $listing->id, 'product_id' => $product->id, 'product_variant_id' => $product->variants()->first()->id, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $code.'-SKU', 'quantity' => 1, 'unit_price' => 1000, 'discount_amount' => 0, 'tax_amount' => 0, 'line_total' => 1000, 'currency_code' => 'MMK']);

        return [$tenant, $admin, $listing, $item, $customer];
    }

    private function business(string $code, string $email): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return [$tenant, $admin];
    }
}
