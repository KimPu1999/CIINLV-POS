<?php

namespace Tests\Feature;

use App\Models\CashFund;
use App\Models\CashManagementEntry;
use App\Models\CashierShift;
use App\Models\PosRegister;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CashManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_petty_cash_entries_are_separate_idempotent_and_private(): void
    {
        Storage::fake('private');
        [$tenant, $admin, , , $store] = $this->fixture('cash-petty');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $fund = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/funds', ['name' => 'Office Petty Cash', 'store_id' => $store->uuid, 'opening_balance' => 100, 'approval_limit' => 50])->assertCreated();
        $fundId = $fund->json('data.id');
        $payload = ['fund_id' => $fundId, 'entry_type' => 'petty_expense', 'amount' => 25, 'category' => 'supplies', 'reason' => 'Printer paper'];
        $first = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'petty-1')->post('/api/v1/business/cash-management/entries', array_merge($payload, ['attachment' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')]))->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'petty-1')->postJson('/api/v1/business/cash-management/entries', $payload)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('cash_management_entries', 1);
        $this->assertDatabaseHas('cash_funds', ['id' => CashFund::where('uuid', $fundId)->value('id')]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/cash-management/overview')->assertOk()->assertJsonPath('data.funds.0.balance', '75.00');
        $this->assertNotSame('cash_sale', CashManagementEntry::first()->entry_type);
        $this->assertTrue((bool) CashManagementEntry::first()->attachment_path);
    }

    public function test_transfers_create_linked_entries_without_becoming_expenses_and_update_register_balance(): void
    {
        [$tenant, $admin, , $cashier, $store] = $this->fixture('cash-transfer');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $fundA = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/funds', ['name' => 'Main Petty Cash', 'store_id' => $store->uuid, 'opening_balance' => 200])->assertCreated()->json('data.id');
        $fundB = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/funds', ['name' => 'Events Petty Cash', 'store_id' => $store->uuid, 'opening_balance' => 0])->assertCreated()->json('data.id');
        $transfer = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'fund-transfer-1')->postJson('/api/v1/business/cash-management/transfers', ['from_type' => 'fund', 'from_id' => $fundA, 'to_type' => 'fund', 'to_id' => $fundB, 'amount' => 60, 'reason' => 'Move event float'])->assertCreated();
        $this->assertCount(2, $transfer->json('data'));
        $this->assertDatabaseCount('cash_management_entries', 2);
        $this->assertDatabaseMissing('cash_management_entries', ['entry_type' => 'petty_expense']);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/cash-management/overview')->assertOk()->assertJsonPath('data.funds.0.balance', '60.00')->assertJsonPath('data.funds.1.balance', '140.00');

        $register = PosRegister::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Register 1', 'code' => 'REG-1', 'status' => 'active', 'created_by' => $admin->id]);
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $cashier->id, 'role' => 'cashier', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/pos/shifts/open', ['store_id' => $store->uuid, 'register_id' => $register->uuid, 'opening_amount' => 100])->assertCreated();
        $shift = CashierShift::where('register_id', $register->id)->firstOrFail();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'register-transfer-1')->postJson('/api/v1/business/cash-management/transfers', ['from_type' => 'fund', 'from_id' => $fundB, 'to_type' => 'register', 'to_id' => $register->uuid, 'amount' => 20, 'reason' => 'Register float'])->assertCreated();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/pos/shifts/current')->assertOk();
        $this->assertSame('120.00', app(\App\Services\CashierShiftService::class)->expected($shift->fresh()));
    }

    public function test_store_manager_entries_over_limit_are_pending_and_admin_can_approve_or_reverse(): void
    {
        [$tenant, $admin, $manager, , $store] = $this->fixture('cash-approval');
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $manager->id, 'role' => 'store_manager', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $fundId = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/funds', ['name' => 'Approval Fund', 'store_id' => $store->uuid, 'opening_balance' => 500, 'approval_limit' => 50])->assertCreated()->json('data.id');
        $entry = $this->actingAs($manager, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'pending-expense-1')->postJson('/api/v1/business/cash-management/entries', ['fund_id' => $fundId, 'entry_type' => 'petty_expense', 'amount' => 100, 'reason' => 'Large purchase'])->assertCreated()->assertJsonPath('data.approval_status', 'pending');
        $entryId = $entry->json('data.id');
        $this->actingAs($manager, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/entries/'.$entryId.'/approve')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/entries/'.$entryId.'/approve')->assertOk()->assertJsonPath('data.0.approval_status', 'approved');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'reverse-pending-expense')->postJson('/api/v1/business/cash-management/entries/'.$entryId.'/reverse')->assertOk();
        $this->assertDatabaseCount('cash_management_entries', 2);
    }

    public function test_history_pagination_is_restricted_and_tenant_scoped(): void
    {
        [$tenantA, $adminA, , , $storeA] = $this->fixture('cash-history-a');
        [$tenantB, $adminB, , , $storeB] = $this->fixture('cash-history-b');
        $headersA = ['X-Tenant-Id' => $tenantA->uuid];
        $headersB = ['X-Tenant-Id' => $tenantB->uuid];
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->postJson('/api/v1/business/cash-management/funds', ['name' => 'History A', 'store_id' => $storeA->uuid, 'opening_balance' => 100])->assertCreated();
        $this->actingAs($adminB, 'sanctum')->withHeaders($headersB)->postJson('/api/v1/business/cash-management/funds', ['name' => 'History B', 'store_id' => $storeB->uuid, 'opening_balance' => 100])->assertCreated();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/business/cash-management/history?per_page=30')->assertUnprocessable();
        $this->actingAs($adminA, 'sanctum')->withHeaders($headersA)->getJson('/api/v1/business/cash-management/history?per_page=10')->assertOk()->assertJsonPath('data.total', 0);
        $this->assertDatabaseCount('cash_funds', 2);
    }

    public function test_history_filters_export_and_reconciliation_are_tenant_and_branch_scoped(): void
    {
        [$tenant, $admin, , , $store] = $this->fixture('cash-history-filters');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $fundId = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/funds', ['name' => 'Filtered Fund', 'store_id' => $store->uuid, 'opening_balance' => 100])->assertCreated()->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'history-filter-entry')->postJson('/api/v1/business/cash-management/entries', ['fund_id' => $fundId, 'entry_type' => 'cash_in', 'amount' => 25, 'category' => 'float', 'reason' => 'Filterable top up'])->assertCreated();

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/cash-management/history?type=cash_in&branch_id='.$store->uuid.'&from='.now()->format('Y-m-d').'&to='.now()->format('Y-m-d').'&per_page=10')->assertOk()->assertJsonPath('data.total', 1);
        $csv = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->get('/api/v1/business/cash-management/history/export?type=cash_in&branch_id='.$store->uuid);
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Transaction ID', $csv->streamedContent());

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'history-reconcile')->postJson('/api/v1/business/cash-management/reconcile', ['source_type' => 'fund', 'source_id' => $fundId, 'actual_amount' => 120, 'notes' => 'Counted cash'])->assertCreated()->assertJsonPath('data.variance', '-5.00');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->getJson('/api/v1/business/cash-management/reconciliations?per_page=10')->assertOk()->assertJsonPath('data.total', 1);
    }

    public function test_invalid_and_insufficient_cash_movements_are_rejected(): void
    {
        [$tenant, $admin, , , $store] = $this->fixture('cash-validation');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $fundId = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/cash-management/funds', ['name' => 'Validation Fund', 'store_id' => $store->uuid, 'opening_balance' => 10])->assertCreated()->json('data.id');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'invalid-amount')->postJson('/api/v1/business/cash-management/entries', ['fund_id' => $fundId, 'entry_type' => 'cash_out', 'amount' => 0, 'reason' => 'Invalid'])->assertUnprocessable();
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->withHeader('Idempotency-Key', 'insufficient-amount')->postJson('/api/v1/business/cash-management/entries', ['fund_id' => $fundId, 'entry_type' => 'cash_out', 'amount' => 11, 'reason' => 'Too much'])->assertUnprocessable();
        $this->assertDatabaseCount('cash_management_entries', 0);
    }

    private function fixture(string $suffix): array
    {
        $code = 'CASH-'.strtoupper(substr(md5($suffix), 0, 8));
        $tenant = Tenant::create(['name' => 'Cash '.$suffix, 'slug' => 'cash-'.$suffix, 'business_code' => $code, 'status' => 'active', 'subscription_status' => 'active']);
        $admin = $this->userWithMembership($tenant, 'cash-admin-'.$suffix.'@example.com', 'business_admin');
        $manager = $this->userWithMembership($tenant, 'cash-manager-'.$suffix.'@example.com', 'store_manager');
        $cashier = $this->userWithMembership($tenant, 'cash-cashier-'.$suffix.'@example.com', 'cashier');
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Cash Store '.$suffix, 'code' => 'STORE-'.strtoupper(substr(md5($suffix), 0, 8)), 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);

        return [$tenant, $admin, $manager, $cashier, $store];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        return $user;
    }
}
