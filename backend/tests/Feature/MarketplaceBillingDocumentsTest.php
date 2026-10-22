<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketplaceBillingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_is_available_before_payment_and_receipt_is_created_by_tenant_scoped_cod_collection(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('BILLING-A', 1500);
        $customer = $this->customer('billing-customer-a@example.com');
        $order = $this->checkout($customer, $listing, $variant, 'billing-a');
        $vendorOrder = $order->vendorOrders()->firstOrFail();

        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/invoice')
            ->assertOk()
            ->assertJsonPath('data.document_type', 'invoice')
            ->assertJsonPath('data.invoice_number', $order->invoice_number)
            ->assertJsonPath('data.amount_due', '1500.00')
            ->assertJsonPath('data.receipt_available', false);
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/receipt')->assertConflict();

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/invoice')->assertOk()->assertJsonPath('data.grand_total', '1500.00');
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1500, 'transaction_reference' => 'CASH-001'])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('marketplace_payments', ['marketplace_order_id' => $order->id, 'status' => 'paid', 'amount_received' => '1500.00', 'transaction_reference' => 'CASH-001']);
        $this->assertDatabaseCount('marketplace_payments', 1);
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->getJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/receipt')->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/receipt')->assertOk()->assertJsonPath('data.receipt_number', 'RCT-MP-'.now()->format('Y').'-'.str_pad((string) $order->payments()->firstOrFail()->id, 6, '0', STR_PAD_LEFT));
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/receipt')->assertOk();
        $this->assertDatabaseCount('marketplace_payments', 1);
    }

    public function test_cod_collection_is_idempotent_and_rejects_invalid_or_duplicate_amounts(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('BILLING-B', 1000);
        $customer = $this->customer('billing-customer-b@example.com');
        $order = $this->checkout($customer, $listing, $variant, 'billing-b');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $url = '/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect';
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson($url, ['amount_received' => 0])->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson($url, ['amount_received' => 1001])->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'billing-b-collect'])->postJson($url, ['amount_received' => 1000])->assertOk()->assertJsonPath('idempotent_replay', false);
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'billing-b-collect'])->postJson($url, ['amount_received' => 1000])->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('marketplace_payments', 1);
        $this->assertDatabaseHas('marketplace_orders', ['id' => $order->id, 'payment_status' => 'paid']);
    }

    public function test_documents_are_scoped_to_customer_and_vendor_and_platform_admin_can_oversee(): void
    {
        [$tenantA, , $listingA, $variantA, $adminA] = $this->vendor('BILLING-C-A', 1100);
        [$tenantB, , , , $adminB] = $this->vendor('BILLING-C-B', 1200);
        $customerA = $this->customer('billing-customer-c@example.com');
        $customerB = $this->customer('billing-customer-d@example.com');
        $order = $this->checkout($customerA, $listingA, $variantA, 'billing-c');
        $vendorOrder = $order->vendorOrders()->firstOrFail();

        $this->actingAs($customerB, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/invoice')->assertNotFound();
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/invoice')->assertNotFound();
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1100])->assertNotFound();
        $this->actingAs($adminA, 'sanctum')->withHeader('X-Tenant-Id', $tenantA->uuid)->getJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/invoice')->assertForbidden();

        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/v1/admin/marketplace/orders/'.$order->uuid.'/invoice')->assertOk()->assertJsonPath('data.order_number', $order->order_number);
    }

    private function checkout(User $customer, MarketplaceListing $listing, ProductVariant $variant, string $key): MarketplaceOrder
    {
        $address = CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 1 Main Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $id = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');

        return MarketplaceOrder::where('uuid', $id)->firstOrFail();
    }

    private function vendor(string $code, int $price): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => strtolower($code).'@example.com']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => $code.' Store', 'slug' => strtolower($code).'-store', 'approval_status' => 'approved', 'is_active' => true]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General', 'slug' => strtolower($code).'-general', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => $code.' Product', 'slug' => strtolower($code).'-product', 'status' => 'active', 'track_inventory' => true, 'tax_exempt' => false]);
        $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $code.'-SKU', 'selling_price' => $price, 'status' => 'active']);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'warehouse', 'name' => $code.' Warehouse', 'code' => $code.'-WH', 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 5, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0]);
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => strtolower($code).'-listing', 'status' => 'published', 'published_at' => now()]);

        return [$tenant, $store, $listing, $variant, $admin];
    }

    private function customer(string $email): User
    {
        $customer = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!'), 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        return $customer;
    }
}
