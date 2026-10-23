<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\InventoryLocation;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseSixPosTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_preview_applies_discounts_tax_charges_and_rounding_from_business_settings(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->posFixture('BUS-A', 'financial-breakdown');
        BusinessSetting::firstOrCreate(['tenant_id' => $tenant->id])->update([
            'tax_configuration' => ['rate' => '10', 'mode' => 'exclusive'],
            'pos_configuration' => [
                'service_charge' => ['enabled' => true, 'type' => 'percentage', 'value' => '5', 'taxable' => false],
                'fees' => [['code' => 'bag', 'label' => 'Bag fee', 'enabled' => true, 'type' => 'fixed', 'value' => '0.15', 'taxable' => true]],
                'rounding' => ['mode' => 'nearest', 'increment' => '0.05'],
            ],
        ]);

        $response = $this->actingAs($admin, 'sanctum')->withHeader('X-Tenant-Id', $tenant->uuid)->postJson('/api/v1/pos/checkout/preview', [
            'store_id' => $store->uuid,
            'items' => [['variant_id' => $variant->uuid, 'quantity' => 2, 'discount_type' => 'percentage', 'discount_value' => '10']],
            'discount_type' => 'fixed',
            'discount_value' => '1.00',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.subtotal', '20.00')
            ->assertJsonPath('data.discount_total', '3.00')
            ->assertJsonPath('data.taxable_amount', '17.15')
            ->assertJsonPath('data.tax_total', '1.72')
            ->assertJsonPath('data.service_charge_total', '0.85')
            ->assertJsonPath('data.fee_total', '0.15')
            ->assertJsonPath('data.rounding_adjustment', '-0.02')
            ->assertJsonPath('data.grand_total', '19.70');
    }

    public function test_cash_sale_is_server_priced_idempotent_deducts_stock_and_generates_receipt_pdf(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->posFixture('BUS-A', 'cash-sale');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $registerId = $this->register($admin, $store, $headers);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $registerId, 'opening_amount' => 100])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/checkout/preview', ['store_id' => $store->uuid, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 2]], 'discount_amount' => 999999])->assertStatus(403);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/checkout/preview', ['store_id' => $store->uuid, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 2]]])->assertOk()->assertJsonPath('data.grand_total', '20.00');
        $salePayload = ['store_id' => $store->uuid, 'register_id' => $registerId, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 2]]];
        $sale = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'sale-cash-1')->postJson('/api/v1/pos/sales', $salePayload)->assertCreated();
        $saleId = $sale->json('data.id');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'sale-cash-1')->postJson('/api/v1/pos/sales', $salePayload)->assertCreated();
        $this->assertDatabaseCount('sales', 1);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'payment-cash-1')->postJson('/api/v1/pos/sales/'.$saleId.'/payments', ['method' => 'cash', 'amount' => 25])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk()->assertJsonPath('data.id', $saleId)->assertJsonPath('data.change_due', '5.00');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk();
        $shiftId = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/current')->assertOk()->json('data.id');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/'.$shiftId.'/close', ['actual_amount' => 120])->assertOk()->assertJsonPath('data.expected_amount', '120.00');
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 8]);
        $this->assertDatabaseCount('stock_movements', 2);
        $this->assertDatabaseCount('receipts', 1);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/sales/'.$saleId.'/receipt')->assertOk()->assertJsonPath('data.receipt.sale_id', $saleId)->assertJsonPath('data.content.grand_total', '20.00')->assertJsonPath('data.content.items.0.size', 'M')->assertJsonPath('data.content.store.name', $store->name)->assertJsonPath('data.content.register', 'Register 1')->assertJsonPath('data.content.cashier', $cashier->name)->assertJsonPath('data.content.salesperson', $cashier->name)->assertJsonPath('data.content.payments.0.method', 'cash')->assertJsonPath('data.content.payments.0.received_amount', '25.00')->assertJsonPath('data.content.payments.0.change_amount', '5.00');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/sales/'.$saleId.'/receipt')->assertOk();
        $this->assertDatabaseCount('receipts', 1);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->get('/api/v1/pos/sales/'.$saleId.'/receipt/pdf?format=58mm')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_unverified_external_payment_cannot_complete_and_authorized_manual_verification_is_idempotent(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->posFixture('BUS-A', 'card-sale');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $store, $headers);
        $this->openShift($cashier, $store, $register, $headers);
        $sale = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'sale-card-1')->postJson('/api/v1/pos/sales', ['store_id' => $store->uuid, 'register_id' => $register, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 1]]])->assertCreated();
        $saleId = $sale->json('data.id');
        $payment = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'payment-card-1')->postJson('/api/v1/pos/sales/'.$saleId.'/payments', ['method' => 'card_terminal', 'amount' => 10, 'provider' => 'terminal-dev', 'external_reference' => 'TERM-001'])->assertCreated();
        $paymentId = $payment->json('data.id');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertUnprocessable();
        config(['pos.allow_manual_payment_verification' => true]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/payments/'.$paymentId.'/verify', ['provider' => 'terminal-dev', 'external_reference' => 'TERM-001', 'verification_note' => 'Authorized terminal reconciliation'])->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/payments/'.$paymentId.'/verify', ['provider' => 'terminal-dev', 'external_reference' => 'TERM-001', 'verification_note' => 'Retry'])->assertOk();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk();
        $this->assertDatabaseHas('payments', ['uuid' => $paymentId, 'status' => 'verified']);
        config(['pos.allow_manual_payment_verification' => false]);
    }

    public function test_cross_tenant_sale_access_and_unassigned_cashier_access_are_blocked(): void
    {
        [$tenantA, $adminA, $cashierA, $storeA, $locationA, $variantA] = $this->posFixture('BUS-A', 'tenant-a');
        [$tenantB, $adminB, $cashierB, $storeB] = $this->posFixture('BUS-B', 'tenant-b');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $registerA = $this->register($adminA, $storeA, $headersA);
        $this->openShift($cashierA, $storeA, $registerA, $headersA);
        $sale = $this->actingAs($cashierA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'sale-tenant-a')->postJson('/api/v1/pos/sales', ['store_id' => $storeA->uuid, 'register_id' => $registerA, 'items' => [['variant_id' => $variantA->uuid, 'quantity' => 1]]])->assertCreated();
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/pos/sales/'.$sale->json('data.id'))->assertNotFound();
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/pos/sales/'.$sale->json('data.id').'/receipt')->assertNotFound();
        $this->actingAs($cashierB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->postJson('/api/v1/pos/shifts/open', ['store_id' => $storeA->uuid, 'register_id' => $registerA, 'opening_amount' => 0])->assertNotFound();
        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    public function test_partial_cash_return_restock_and_duplicate_refund_are_safe(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->posFixture('BUS-A', 'returns');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $store, $headers);
        $this->openShift($cashier, $store, $register, $headers);
        $sale = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'sale-return-1')->postJson('/api/v1/pos/sales', ['store_id' => $store->uuid, 'register_id' => $register, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 3]]])->assertCreated();
        $saleId = $sale->json('data.id');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'payment-return-1')->postJson('/api/v1/pos/sales/'.$saleId.'/payments', ['method' => 'cash', 'amount' => 30])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk();
        $saleItemId = $sale->json('data.items.0.id');
        $return = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'return-1')->postJson('/api/v1/pos/sales/'.$saleId.'/returns', ['reason' => 'Customer changed mind', 'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1, 'restock' => true, 'disposition' => 'sellable']]])->assertCreated();
        $returnId = $return->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/returns/'.$returnId.'/approve')->assertOk();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'refund-1')->postJson('/api/v1/pos/returns/'.$returnId.'/refund', ['method' => 'cash'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'refund-1')->postJson('/api/v1/pos/returns/'.$returnId.'/refund', ['method' => 'cash'])->assertCreated();
        $this->assertDatabaseHas('inventory_balances', ['inventory_location_id' => $location->id, 'product_variant_id' => $variant->id, 'on_hand_quantity' => 8]);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('sale_items', ['uuid' => $saleItemId, 'returned_quantity' => 1]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/dashboard/sales-summary')->assertOk()->assertJsonPath('data.net_revenue', '20.00')->assertJsonPath('data.cogs', '10.0000')->assertJsonPath('data.gross_profit', '10.0000');
    }

    public function test_returns_management_is_tenant_scoped_and_statistics_count_succeeded_refunds(): void
    {
        [$tenantA, $adminA, $cashierA, $storeA, $locationA, $variantA] = $this->posFixture('BUS-A', 'return-management');
        [$tenantB, $adminB] = $this->posFixture('BUS-B', 'return-isolation');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $register = $this->register($adminA, $storeA, $headersA);
        $this->openShift($cashierA, $storeA, $register, $headersA);
        $sale = $this->actingAs($cashierA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'sale-management-1')->postJson('/api/v1/pos/sales', ['store_id' => $storeA->uuid, 'register_id' => $register, 'items' => [['variant_id' => $variantA->uuid, 'quantity' => 1]]])->assertCreated();
        $saleId = $sale->json('data.id');
        $this->actingAs($cashierA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'payment-management-1')->postJson('/api/v1/pos/sales/'.$saleId.'/payments', ['method' => 'cash', 'amount' => 10])->assertCreated();
        $this->actingAs($cashierA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk();
        $return = $this->actingAs($cashierA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'return-management-1')->postJson('/api/v1/pos/sales/'.$saleId.'/returns', ['reason' => 'Management test', 'items' => [['sale_item_id' => $sale->json('data.items.0.id'), 'quantity' => 1, 'restock' => false, 'disposition' => 'damaged']]])->assertCreated();
        $returnId = $return->json('data.id');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/pos/returns/'.$returnId.'/approve')->assertOk();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->withHeader('Idempotency-Key', 'refund-management-1')->postJson('/api/v1/pos/returns/'.$returnId.'/refund', ['method' => 'cash'])->assertCreated();

        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/pos/returns?search='.$return->json('data.return_number'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'refunded');
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/pos/returns/statistics')->assertOk()->assertJsonPath('data.total_returns', 1)->assertJsonPath('data.pending_returns', 0)->assertJsonPath('data.completed_returns', 1)->assertJsonPath('data.actual_refunds', '10.00')->assertJsonPath('data.refunds_by_payment_method.cash', '10.00');
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/pos/returns')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($adminB, 'sanctum')->withHeader('X-Tenant-Id', $tenantB->uuid)->getJson('/api/v1/pos/returns/statistics')->assertOk()->assertJsonPath('data.total_returns', 0)->assertJsonPath('data.actual_refunds', '0.00');
    }

    public function test_duplicate_open_shift_is_rejected_and_reconciliation_uses_cash_tender_after_change(): void
    {
        [$tenant, $admin, $cashier, $store] = $this->posFixture('BUS-A', 'shift');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $store, $headers);
        $this->openShift($cashier, $store, $register, $headers);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $register, 'opening_amount' => 100])->assertConflict();
        $this->assertDatabaseCount('cash_movements', 1);
        $shift = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/current')->assertOk()->json('data.id');
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'cash-in-1')->postJson('/api/v1/pos/shifts/'.$shift.'/cash-movements', ['type' => 'cash_in', 'amount' => 10, 'reason' => 'Till top-up'])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/'.$shift.'/close', ['actual_amount' => 110])->assertOk()->assertJsonPath('data.cash_difference', '0.00');
    }

    public function test_zero_opening_cash_creates_a_shift_without_a_zero_value_movement_and_duplicate_open_is_safe(): void
    {
        [$tenant, $admin, $cashier, $store] = $this->posFixture('BUS-A', 'zero-opening-cash');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $store, $headers);
        $payload = ['store_id' => $store->uuid, 'register_id' => $register, 'opening_amount' => 0];

        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'shift-zero-opening')->postJson('/api/v1/pos/shifts/open', $payload)->assertCreated()->assertJsonPath('data.opening_amount', '0.00');
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('cashier_shifts', 1);

        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'shift-zero-opening')->postJson('/api/v1/pos/shifts/open', $payload)->assertConflict();
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseCount('cashier_shifts', 1);
    }

    public function test_positive_opening_cash_creates_exactly_one_opening_float_movement(): void
    {
        [$tenant, $admin, $cashier, $store] = $this->posFixture('BUS-A', 'positive-opening-cash');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $store, $headers);

        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'shift-positive-opening')->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $register, 'opening_amount' => 100000])->assertCreated();

        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseHas('cash_movements', ['movement_type' => 'opening_float', 'amount' => '100000.00', 'reference_type' => 'cashier_shift']);
    }

    public function test_negative_opening_cash_is_rejected_without_creating_a_shift_or_movement(): void
    {
        [$tenant, $admin, $cashier, $store] = $this->posFixture('BUS-A', 'negative-opening-cash');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $register = $this->register($admin, $store, $headers);

        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $register, 'opening_amount' => -1])->assertUnprocessable();
        $this->assertDatabaseCount('cashier_shifts', 0);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_multiple_registers_have_independent_branch_scoped_shifts_and_sales_context(): void
    {
        [$tenant, $admin, $cashierA, $store, $location, $variant] = $this->posFixture('BUS-A', 'multiple-registers');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $cashierB = $this->userWithMembership($tenant, 'cashier-b-multiple-registers@example.com', 'cashier');
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $cashierB->id, 'role' => 'cashier', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $registerA = $this->register($admin, $store, $headers);
        $registerB = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers', ['store_id' => $store->uuid, 'name' => 'Counter 2', 'code' => 'REG-002'])->assertCreated()->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers/'.$registerB.'/members', ['user_id' => $cashierA->id])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers/'.$registerB.'/members', ['user_id' => $cashierB->id])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers/'.$registerA.'/members', ['user_id' => $cashierB->id])->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/registers?store_id='.$store->uuid.'&active_only=1')->assertOk()->assertJsonCount(2, 'data');
        $this->openShift($cashierA, $store, $registerA, $headers);
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'shift-register-b')->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $registerB, 'opening_amount' => 100])->assertCreated();
        $this->actingAs($cashierA, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/register-status?store_id='.$store->uuid.'&register_id='.$registerA)->assertOk()->assertJsonPath('data.state', 'current');
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/register-status?store_id='.$store->uuid.'&register_id='.$registerA)->assertOk()->assertJsonPath('data.state', 'in_use')->assertJsonPath('data.shift.cashier_name', $cashierA->name);
        $this->actingAs($cashierA, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'same-cashier-second-register')->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $registerB, 'opening_amount' => 0])->assertConflict();
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'same-register-second-cashier')->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $registerA, 'opening_amount' => 0])->assertConflict();

        $shiftB = $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/register-status?store_id='.$store->uuid.'&register_id='.$registerB)->assertOk()->json('data.shift.id');
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/'.$shiftB.'/close', ['actual_amount' => 100])->assertOk();
        $shiftA = $this->actingAs($cashierA, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/register-status?store_id='.$store->uuid.'&register_id='.$registerA)->json('data.shift.id');
        $this->actingAs($cashierA, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/'.$shiftA.'/close', ['actual_amount' => 100])->assertOk();
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'reopen-closed-register')->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $registerA, 'opening_amount' => 0])->assertCreated();

        $sale = $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'sale-register-context')->postJson('/api/v1/pos/sales', ['store_id' => $store->uuid, 'register_id' => $registerA, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 1]]])->assertCreated();
        $saleId = $sale->json('data.id');
        $shiftId = $sale->json('data.cashier_shift_id');
        $sale->assertJsonPath('data.register_id', $registerA);
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'payment-register-context')->postJson('/api/v1/pos/sales/'.$saleId.'/payments', ['method' => 'cash', 'amount' => 10, 'received_amount' => 10])->assertCreated();
        $this->actingAs($cashierB, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk();
        $this->assertDatabaseHas('sales', ['uuid' => $saleId, 'register_id' => PosRegister::where('uuid', $registerA)->value('id'), 'cashier_shift_id' => $shiftId, 'cashier_user_id' => $cashierB->id]);
        $this->assertDatabaseHas('payments', ['sale_id' => Sale::where('uuid', $saleId)->value('id'), 'register_id' => PosRegister::where('uuid', $registerA)->value('id'), 'cashier_shift_id' => $shiftId]);
    }

    public function test_branch_salespeople_are_filtered_and_recorded_on_sale_and_payment_context(): void
    {
        [$tenant, $admin, $cashier, $store, $location, $variant] = $this->posFixture('BUS-A', 'salesperson-context');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $salesperson = $this->userWithMembership($tenant, 'salesperson-context@example.com', 'cashier');
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $salesperson->id, 'role' => 'salesperson', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $register = $this->register($admin, $store, $headers);

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/registers?store_id='.$store->uuid.'&active_only=1')->assertOk()->assertJsonCount(1, 'data');
        $salespeople = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/salespersons?store_id='.$store->uuid)->assertOk();
        $salespeople->assertJsonFragment(['id' => $salesperson->id, 'name' => $salesperson->name, 'role' => 'salesperson']);

        $this->openShift($cashier, $store, $register, $headers);
        $sale = $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'sale-salesperson-context')->postJson('/api/v1/pos/sales', ['store_id' => $store->uuid, 'register_id' => $register, 'salesperson_id' => $salesperson->id, 'items' => [['variant_id' => $variant->uuid, 'quantity' => 1]]])->assertCreated();
        $saleId = $sale->json('data.id');
        $shiftId = $sale->json('data.cashier_shift_id');
        $this->assertNotNull($shiftId);
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'payment-salesperson-context')->postJson('/api/v1/pos/sales/'.$saleId.'/payments', ['method' => 'cash', 'amount' => 12, 'received_amount' => 12])->assertCreated();
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/sales/'.$saleId.'/complete')->assertOk()->assertJsonPath('data.salesperson_user_id', $salesperson->id);
        $this->assertDatabaseHas('sales', ['uuid' => $saleId, 'cashier_shift_id' => $shiftId, 'salesperson_user_id' => $salesperson->id]);
        $this->assertDatabaseHas('payments', ['sale_id' => Sale::where('uuid', $saleId)->value('id'), 'cashier_shift_id' => $shiftId, 'received_amount' => '12.00', 'change_amount' => '2.00']);
    }

    private function posFixture(string $code, string $suffix): array
    {
        $tenant = Tenant::create(['name' => $code, 'slug' => strtolower($code.'-'.$suffix), 'business_code' => $code.'-'.strtoupper(substr($suffix, 0, 3)), 'status' => 'active', 'subscription_status' => 'active']);
        $admin = $this->userWithMembership($tenant, 'admin-'.$suffix.'@example.com', 'business_admin');
        $cashier = $this->userWithMembership($tenant, 'cashier-'.$suffix.'@example.com', 'cashier');
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Store '.$suffix, 'code' => 'STORE-'.strtoupper(substr($suffix, 0, 3)), 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $cashier->id, 'role' => 'cashier', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $location = InventoryLocation::create(['tenant_id' => $tenant->id, 'location_type' => 'store', 'store_id' => $store->id, 'name' => 'Store Stockroom', 'code' => 'STOCK-'.strtoupper(substr($suffix, 0, 3)), 'status' => 'active']);
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Coffee '.$suffix, 'slug' => strtolower('coffee-'.$suffix), 'status' => 'active', 'track_inventory' => true, 'has_sizes' => true, 'created_by' => $admin->id]);
        $variant = $product->variants()->create(['tenant_id' => $tenant->id, 'sku' => 'SKU-'.strtoupper(substr($suffix, 0, 3)), 'attributes' => ['size' => 'M'], 'selling_price' => 10, 'purchase_cost' => 5, 'status' => 'active']);
        app(InventoryService::class)->mutate($location, $variant, 'opening_stock', '10', $admin, 'test', 'opening-'.$suffix, null, 'test-opening', '5');

        return [$tenant, $admin, $cashier, $store, $location, $variant];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    private function register(User $admin, Store $store, array $headers): string
    {
        $register = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers', ['store_id' => $store->uuid, 'name' => 'Register 1', 'code' => 'REG-001'])->assertCreated();
        $cashierId = StoreMembership::where('store_id', $store->id)->where('role', 'cashier')->value('user_id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/registers/'.$register->json('data.id').'/members', ['user_id' => $cashierId])->assertCreated();

        return $register->json('data.id');
    }

    private function openShift(User $cashier, Store $store, string $register, array $headers): void
    {
        $this->actingAs($cashier, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $register, 'opening_amount' => 100])->assertCreated();
    }
}
