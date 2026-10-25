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

class MarketplaceFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_can_create_and_progress_cod_shipment_while_payment_remains_pending(): void
    {
        [$tenant, $store, $listing, $variant, $admin] = $this->vendor('FULFILLMENT-A', 1000, 2);
        $customer = $this->customer('fulfillment-a@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-a-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();

        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated()->assertJsonPath('data.status', 'ready')->json('data');
        $this->assertStringStartsWith('CIINLV-DEL-', $shipment['internal_tracking_id']);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'shipped'])->assertOk()->assertJsonPath('data.status', 'shipped');
        $this->assertDatabaseHas('marketplace_payments', ['marketplace_order_id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('inventory_balances', ['tenant_id' => $tenant->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => '1.000', 'reserved_quantity' => '0.000']);

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'in_transit'])->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'delivered'])->assertOk()->assertJsonPath('data.status', 'delivered');
        $this->assertDatabaseHas('marketplace_orders', ['id' => $order->id, 'status' => 'delivered', 'payment_status' => 'pending']);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_cod_collection_requires_active_fulfillment_and_is_idempotent_after_payment(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-PAYMENT', 1000, 2);
        $customer = $this->customer('fulfillment-payment@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-payment-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $headers = ['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'fulfillment-payment-collect'];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fulfillment must be active before receiving cash payment.');

        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.fulfillment_status', 'active')
            ->json('data');

        $vendorOrder->refresh()->update(['fulfillment_status' => 'ready']);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fulfillment must be active before receiving cash payment.');
        $vendorOrder->refresh()->update(['fulfillment_status' => 'active']);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.receipt_available', true);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        foreach (['shipped', 'in_transit', 'delivered'] as $status) {
            $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
                ->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => $status])
                ->assertOk();
        }
        $this->assertDatabaseHas('marketplace_payments', ['marketplace_order_id' => $order->id, 'status' => 'paid']);
    }

    public function test_external_courier_cod_requires_reconciliation_reference(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-COURIER-COD', 1000, 2);
        $customer = $this->customer('fulfillment-courier-cod@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-courier-cod-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'external_courier', 'carrier_name' => 'Test Courier', 'tracking_number' => 'TRACK-1000'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertConflict()
            ->assertJsonPath('message', 'Courier-collected COD must be reconciled before it is recorded as received by the business.');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', [
                'amount_received' => 1000,
                'collection_source' => 'courier_reconciled',
                'courier_reference' => 'COURIER-1000',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        $this->assertDatabaseHas('marketplace_payments', [
            'marketplace_order_id' => $order->id,
            'status' => 'paid',
            'transaction_reference' => null,
        ]);
        $this->assertDatabaseHas('marketplace_orders', [
            'id' => $order->id,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
        $this->assertNotEmpty($shipment['id']);
    }

    public function test_cancelled_fulfillment_blocks_pending_cod_collection(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-CANCELLED-PAYMENT', 1000, 2);
        $customer = $this->customer('fulfillment-cancelled-payment@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-cancelled-payment-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])
            ->assertCreated()
            ->json('data');
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'cancelled'])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)
            ->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/payments/cod/collect', ['amount_received' => 1000])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Fulfillment must be active before receiving cash payment.');
    }

    public function test_invalid_shipment_transition_and_duplicate_stock_dispatch_are_rejected(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-B', 1000, 2);
        $customer = $this->customer('fulfillment-b@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-b-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'external_courier', 'carrier_name' => 'Courier MM', 'tracking_number' => 'TRACK-001', 'tracking_url' => 'https://courier.example/track/TRACK-001'])->assertCreated()->json('data');

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'delivered'])->assertConflict();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'shipped'])->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => 'shipped'])->assertConflict();
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('marketplace_inventory_reservations', 1);
        $this->assertDatabaseHas('marketplace_inventory_reservations', ['status' => 'confirmed']);
    }

    public function test_customer_tracking_and_vendor_access_are_scoped(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-C', 1000, 2);
        $customer = $this->customer('fulfillment-c@example.com');
        $otherCustomer = $this->customer('fulfillment-c-other@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-c-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated()->json('data');

        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/tracking')->assertOk()->assertJsonPath('data.vendor_orders.0.shipments.0.id', $shipment['id']);
        $this->actingAs($otherCustomer, 'sanctum')->getJson('/api/v1/marketplace/orders/'.$order->uuid.'/tracking')->assertNotFound();
    }

    public function test_cancellation_approval_releases_reservation_once_and_prevents_repeat_requests(): void
    {
        [$tenant, , $listing, $variant] = $this->vendor('FULFILLMENT-D', 1000, 2);
        $customer = $this->customer('fulfillment-d@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-d-checkout');

        $request = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/orders/'.$order->uuid.'/cancellation-requests', ['reason' => 'Please cancel before dispatch.'])->assertCreated()->json('data');
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/orders/'.$order->uuid.'/cancellation-requests', ['reason' => 'Duplicate request'])->assertConflict();
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/cancellation-requests/'.$request['id'].'/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('marketplace_orders', ['id' => $order->id, 'status' => 'cancelled', 'payment_status' => 'cancelled']);
        $this->assertDatabaseHas('marketplace_inventory_reservations', ['marketplace_order_id' => $order->id, 'status' => 'released']);
        $this->assertSame('0.000', (string) InventoryBalance::where('product_variant_id', $variant->id)->value('reserved_quantity'));
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/cancellation-requests/'.$request['id'].'/decision', ['decision' => 'rejected'])->assertConflict();
    }

    public function test_return_approval_blocks_real_money_refund_until_provider_is_configured(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-E', 1000, 2);
        $customer = $this->customer('fulfillment-e@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-e-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $shipment = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertCreated()->json('data');
        foreach (['shipped', 'in_transit', 'delivered'] as $status) {
            $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->patchJson('/api/v1/vendor/marketplace/shipments/'.$shipment['id'].'/status', ['status' => $status])->assertOk();
        }
        $item = $order->items()->firstOrFail();
        $return = $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/orders/'.$order->uuid.'/return-requests', ['reason' => 'Item is not suitable.', 'items' => [['item_id' => $item->uuid, 'quantity' => 1]]])->assertCreated()->json('data');
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/orders/'.$order->uuid.'/return-requests', ['reason' => 'Duplicate return.', 'items' => [['item_id' => $item->uuid, 'quantity' => 1]]])->assertConflict();
        $superAdmin = User::factory()->create(['platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN]);
        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/marketplace/return-requests/'.$return['id'].'/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseHas('marketplace_refund_requests', ['marketplace_order_id' => $order->id, 'status' => 'blocked']);
        $this->assertDatabaseMissing('marketplace_payments', ['status' => 'refunded']);
    }

    public function test_vendor_cannot_access_another_business_order_or_shipment(): void
    {
        [$tenantA, , $listing, $variant] = $this->vendor('FULFILLMENT-G', 1000, 2);
        [$tenantB, , , , $adminB] = $this->vendor('FULFILLMENT-H', 1000, 2);
        $customer = $this->customer('fulfillment-g@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-g-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();

        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid)->assertNotFound();
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'self_delivery'])->assertNotFound();
        $this->assertDatabaseHas('marketplace_orders', ['id' => $order->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenantA->id]);
    }

    public function test_external_shipment_requires_actual_carrier_details_and_safe_url(): void
    {
        [$tenant, , $listing, $variant, $admin] = $this->vendor('FULFILLMENT-F', 1000, 2);
        $customer = $this->customer('fulfillment-f@example.com');
        $address = $this->address($customer);
        $this->addToCart($customer, $listing, $variant);
        $order = $this->checkout($customer, $address, 'fulfillment-f-checkout');
        $vendorOrder = $order->vendorOrders()->firstOrFail();

        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/vendor/marketplace/orders/'.$vendorOrder->uuid.'/shipments', ['delivery_type' => 'external_courier', 'tracking_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors(['carrier_name', 'tracking_number', 'tracking_url']);
        $this->assertDatabaseCount('marketplace_shipments', 0);
    }

    private function addToCart(User $customer, MarketplaceListing $listing, ProductVariant $variant): void
    {
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
    }

    private function checkout(User $customer, CustomerAddress $address, string $key): MarketplaceOrder
    {
        $id = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');

        return MarketplaceOrder::where('uuid', $id)->firstOrFail();
    }

    private function vendor(string $code, int $price, int $stock): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = User::factory()->create(['email' => strtolower($code).'-admin@example.com']);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'business_admin']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $store = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => $code.' Store', 'slug' => strtolower($code).'-store', 'approval_status' => 'approved', 'is_active' => true]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => 'General', 'slug' => strtolower($code).'-general', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => $code.' Product', 'slug' => strtolower($code).'-product', 'status' => 'active', 'track_inventory' => true, 'tax_exempt' => false]);
        $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $code.'-SKU', 'selling_price' => $price, 'status' => 'active']);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'warehouse', 'name' => $code.' Warehouse', 'code' => $code.'-WH', 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => $stock, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0]);
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $store->id, 'product_id' => $product->id, 'slug' => strtolower($code).'-listing', 'status' => 'published', 'published_at' => now()]);

        return [$tenant, $store, $listing, $variant, $admin];
    }

    private function customer(string $email): User
    {
        $customer = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!'), 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);

        return $customer;
    }

    private function address(User $customer): CustomerAddress
    {
        return CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 1 Main Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
    }
}
