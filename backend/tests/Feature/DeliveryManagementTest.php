<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\DeliveryStaffProfile;
use App\Models\EmployeeProfile;
use App\Models\InventoryBalance;
use App\Models\InventoryLocation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeliveryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_is_search_ready_and_excludes_inactive_profiles(): void
    {
        [$tenant, $admin, $rider, $store] = $this->business('DELIVERY-LOOKUP');
        $active = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $rider->id, 'employee_number' => 'EMP-LOOKUP-001', 'job_title' => 'Salesperson', 'phone' => '0912345678', 'employment_status' => 'active']);
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $rider->id, 'role' => 'salesperson', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $inactiveUser = $this->userWithMembership($tenant, 'inactive-delivery@example.com', 'salesperson');
        EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $inactiveUser->id, 'employee_number' => 'EMP-LOOKUP-002', 'employment_status' => 'terminated']);

        $response = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->getJson('/api/v1/business/delivery-management/lookup')->assertOk();
        $response->assertJsonPath('data.employees.0.id', $active->uuid)->assertJsonPath('data.employees.0.employee_number', 'EMP-LOOKUP-001')->assertJsonPath('data.employees.0.job_role', 'Salesperson')->assertJsonPath('data.employees.0.branches.0.name', $store->name);
        $this->assertCount(1, $response->json('data.employees'));
        $this->actingAs($rider, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->getJson('/api/v1/business/delivery-management/lookup')->assertForbidden();
    }

    public function test_unlinked_employee_record_can_be_registered_as_delivery_staff(): void
    {
        [$tenant, $admin, , $store] = $this->business('DELIVERY-RECORD-ONLY');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'name' => 'Delivery Worker', 'employee_number' => 'EMP-DLV-RECORD', 'employment_status' => 'active']);
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/business/delivery-management/staff', ['employee_profile_id' => $profile->uuid, 'store_id' => $store->uuid, 'vehicle_type' => 'Bike'])->assertCreated()->assertJsonPath('data.employee.name', 'Delivery Worker');
    }

    public function test_inactive_employee_cannot_be_registered_as_delivery_staff(): void
    {
        [$tenant, $admin, $rider] = $this->business('DELIVERY-INACTIVE');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $rider->id, 'employee_number' => 'EMP-INACTIVE', 'employment_status' => 'terminated']);
        $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/business/delivery-management/staff', ['employee_profile_id' => $profile->uuid])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_staff_profiles', 0);
    }

    public function test_staff_directory_reuses_employee_identity_and_is_tenant_scoped(): void
    {
        [$tenant, $admin, $rider, $store] = $this->business('DELIVERY-STAFF-A');
        [$otherTenant, $otherAdmin] = $this->business('DELIVERY-STAFF-B');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $rider->id, 'employee_number' => 'EMP-DLV-001', 'phone' => '0912345678', 'employment_status' => 'active']);
        $headers = ['X-Tenant-Id' => $tenant->uuid];

        $staff = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/delivery-management/staff', ['employee_profile_id' => $profile->uuid, 'store_id' => $store->uuid, 'vehicle_type' => 'Motorbike'])->assertCreated()->assertJsonPath('data.employee.name', $rider->name)->json('data');
        $this->assertDatabaseHas('delivery_staff_profiles', ['tenant_id' => $tenant->id, 'employee_profile_id' => $profile->id, 'store_id' => $store->id]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/delivery-management/staff', ['employee_profile_id' => $profile->uuid])->assertConflict();
        $this->actingAs($otherAdmin, 'sanctum')->withHeaders(['X-Tenant-Id' => $otherTenant->uuid])->getJson('/api/v1/business/delivery-management/staff')->assertOk()->assertJsonPath('data.total', 0);
        $this->actingAs($otherAdmin, 'sanctum')->withHeaders(['X-Tenant-Id' => $otherTenant->uuid])->postJson('/api/v1/business/delivery-management/staff/'.$staff['id'].'/availability', ['availability_status' => 'off_duty'])->assertNotFound();
    }

    public function test_assignment_is_idempotent_and_progresses_through_existing_fulfillment_states(): void
    {
        [$tenant, $admin, $listing, $variant, $store] = $this->vendor('DELIVERY-FLOW', 1000, 2);
        $rider = $this->userWithMembership($tenant, 'delivery-rider@example.com', 'salesperson');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $rider->id, 'employee_number' => 'EMP-DLV-FLOW', 'employment_status' => 'active']);
        $staffResponse = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/business/delivery-management/staff', ['employee_profile_id' => $profile->uuid, 'store_id' => $store->uuid])->assertCreated();
        $staffId = $staffResponse->json('data.id');
        $customer = $this->customer('delivery-flow-customer@example.com');
        $address = CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 1 Delivery Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $orderId = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'delivery-flow-order')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');
        $order = MarketplaceOrder::query()->where('uuid', $orderId)->firstOrFail();
        $vendorOrder = $order->vendorOrders()->firstOrFail();
        $headers = ['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'delivery-assignment-1'];

        $assignment = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/delivery-management/assignments', ['order_id' => $vendorOrder->uuid, 'delivery_type' => 'self_delivery', 'staff_id' => $staffId])->assertCreated()->assertJsonPath('data.assignment_status', 'assigned')->json('data');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/delivery-management/assignments', ['order_id' => $vendorOrder->uuid, 'delivery_type' => 'self_delivery', 'staff_id' => $staffId])->assertOk()->assertJsonPath('idempotent_replay', true);
        $this->assertDatabaseCount('marketplace_shipments', 1);

        foreach ([['accepted', 'ready'], ['picked_up', 'shipped'], ['in_transit', 'in_transit'], ['delivered', 'delivered']] as [$deliveryStatus, $shipmentStatus]) {
            $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/business/delivery-management/shipments/'.$assignment['id'].'/status', ['status' => $deliveryStatus])->assertOk()->assertJsonPath('data.assignment_status', $deliveryStatus)->assertJsonPath('data.status', $shipmentStatus);
        }
        $this->assertDatabaseHas('delivery_assignment_events', ['event_type' => 'accepted']);
        $this->assertDatabaseHas('delivery_assignment_events', ['event_type' => 'delivered']);
        $this->assertDatabaseHas('delivery_staff_profiles', ['uuid' => $staffId, 'availability_status' => 'available']);
        $this->assertDatabaseHas('marketplace_orders', ['id' => $order->id, 'payment_status' => 'pending']);
    }

    public function test_unavailable_staff_and_cross_tenant_shipment_are_rejected(): void
    {
        [$tenant, $admin, $listing, $variant, $store] = $this->vendor('DELIVERY-AUTH', 1000, 2);
        [$otherTenant, $otherAdmin] = $this->vendor('DELIVERY-AUTH-OTHER', 1000, 2);
        $rider = $this->userWithMembership($tenant, 'delivery-unavailable@example.com', 'salesperson');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $rider->id, 'employee_number' => 'EMP-DLV-OFF', 'employment_status' => 'active']);
        $staff = DeliveryStaffProfile::create(['tenant_id' => $tenant->id, 'employee_profile_id' => $profile->id, 'store_id' => $store->id, 'staff_code' => 'DLV-OFF', 'availability_status' => 'off_duty', 'status' => 'active', 'created_by' => $admin->id]);
        $customer = $this->customer('delivery-auth-customer@example.com');
        $address = CustomerAddress::create(['customer_profile_id' => $customer->customerProfile->id, 'recipient_name' => $customer->name, 'address_line_1' => 'No. 2 Delivery Street', 'city' => 'Yangon', 'country' => 'MM', 'is_default' => true]);
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/marketplace/cart/items', ['listing_id' => $listing->uuid, 'variant_id' => $variant->uuid, 'quantity' => 1])->assertCreated();
        $orderId = $this->actingAs($customer, 'sanctum')->withHeader('Idempotency-Key', 'delivery-auth-order')->postJson('/api/v1/marketplace/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->json('data.id');
        $vendorOrder = MarketplaceOrder::query()->where('uuid', $orderId)->firstOrFail()->vendorOrders()->firstOrFail();
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid, 'Idempotency-Key' => 'delivery-auth-assignment'])->postJson('/api/v1/business/delivery-management/assignments', ['order_id' => $vendorOrder->uuid, 'delivery_type' => 'self_delivery', 'staff_id' => $staff->uuid])->assertConflict();
        $this->actingAs($otherAdmin, 'sanctum')->withHeader('X-Tenant-Id', $otherTenant->uuid)->postJson('/api/v1/business/delivery-management/shipments/'.$staff->uuid.'/status', ['status' => 'accepted'])->assertNotFound();
    }

    private function business(string $code): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code), 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = $this->userWithMembership($tenant, strtolower($code).'-admin@example.com', 'business_admin');
        $rider = $this->userWithMembership($tenant, strtolower($code).'-rider@example.com', 'salesperson');
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => $code.' Branch', 'code' => $code.'-BR', 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        return [$tenant, $admin, $rider, $store];
    }

    private function vendor(string $code, int $price, int $stock): array
    {
        [$tenant, $admin, , $store] = $this->business($code);
        $marketplaceStore = MarketplaceStore::create(['tenant_id' => $tenant->id, 'name' => $code.' Marketplace', 'slug' => strtolower($code).'-marketplace', 'approval_status' => 'approved', 'is_active' => true]);
        $category = Category::create(['tenant_id' => $tenant->id, 'name' => $code.' General', 'slug' => strtolower($code).'-general', 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => $code.' Product', 'slug' => strtolower($code).'-product', 'status' => 'active', 'track_inventory' => true, 'tax_exempt' => false]);
        $variant = ProductVariant::create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'sku' => $code.'-SKU', 'selling_price' => $price, 'status' => 'active']);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'warehouse', 'name' => $code.' Warehouse', 'code' => $code.'-WH', 'status' => 'active']);
        InventoryBalance::create(['tenant_id' => $tenant->id, 'inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => $stock, 'reserved_quantity' => 0, 'average_cost' => 0, 'version' => 0]);
        $listing = MarketplaceListing::create(['tenant_id' => $tenant->id, 'marketplace_store_id' => $marketplaceStore->id, 'product_id' => $product->id, 'slug' => strtolower($code).'-listing', 'status' => 'published', 'published_at' => now()]);
        return [$tenant, $admin, $listing, $variant, $store];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        return $user;
    }

    private function customer(string $email): User
    {
        $customer = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!'), 'platform_role' => null]);
        CustomerProfile::create(['user_id' => $customer->id, 'status' => 'active']);
        return $customer;
    }
}
