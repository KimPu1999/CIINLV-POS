<?php

namespace Tests\Feature;

use App\Models\EmployeeCommission;
use App\Models\EmployeeProfile;
use App\Models\PosRegister;
use App\Models\PosRegisterMembership;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_admin_can_create_existing_user_profile_and_duplicate_is_rejected(): void
    {
        [$tenant, $admin, $employee, $store] = $this->fixture('create');
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $payload = ['user_id' => $employee->id, 'employee_number' => 'EMP-001', 'job_title' => 'Cashier', 'department' => 'Sales', 'employment_type' => 'full_time', 'branch_id' => $store->uuid, 'branch_role' => 'cashier'];

        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->post('/api/v1/business/employees', $payload)->assertCreated()->assertJsonPath('data.employee_number', 'EMP-001');
        $this->assertDatabaseHas('employee_profiles', ['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'employee_number' => 'EMP-001']);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees', $payload)->assertStatus(409);
        $this->assertSame(1, EmployeeProfile::where('tenant_id', $tenant->id)->count());
    }

    public function test_employee_directory_is_paginated_and_tenant_scoped(): void
    {
        [$tenantA, $adminA, $employeeA] = $this->fixture('tenant-a');
        [$tenantB, $adminB, $employeeB] = $this->fixture('tenant-b');
        EmployeeProfile::create(['tenant_id' => $tenantA->id, 'user_id' => $employeeA->id, 'employee_number' => 'A-001']);
        EmployeeProfile::create(['tenant_id' => $tenantB->id, 'user_id' => $employeeB->id, 'employee_number' => 'B-001']);

        $response = $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->getJson('/api/v1/business/employees?per_page=10')->assertOk();
        $response->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.employee_number', 'A-001');
        $this->actingAs($adminB, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantB->uuid])->getJson('/api/v1/business/employees?per_page=100')->assertOk()->assertJsonPath('data.data.0.employee_number', 'B-001');
        $this->actingAs($adminA, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenantA->uuid])->getJson('/api/v1/business/employees?per_page=30')->assertUnprocessable();
    }

    public function test_deactivation_revokes_access_but_does_not_delete_membership_history(): void
    {
        [$tenant, $admin, $employee, $store] = $this->fixture('deactivate');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'employee_number' => 'EMP-002']);
        $membership = StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $employee->id, 'role' => 'cashier', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $register = PosRegister::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'name' => 'Register 1', 'code' => 'EMP-REG-1', 'status' => 'active', 'created_by' => $admin->id]);
        $registerMembership = PosRegisterMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'register_id' => $register->id, 'user_id' => $employee->id, 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);

        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->postJson('/api/v1/business/employees/'.$profile->uuid.'/deactivate')->assertOk();
        $this->assertDatabaseHas('employee_profiles', ['id' => $profile->id, 'employment_status' => 'terminated']);
        $this->assertDatabaseHas('store_memberships', ['id' => $membership->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('pos_register_memberships', ['id' => $registerMembership->id, 'status' => 'revoked']);
    }

    public function test_attendance_clock_in_out_is_idempotent_for_a_day(): void
    {
        [$tenant, $admin, $employee, $store] = $this->fixture('attendance');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'employee_number' => 'EMP-003']);
        StoreMembership::create(['tenant_id' => $tenant->id, 'store_id' => $store->id, 'user_id' => $employee->id, 'role' => 'salesperson', 'status' => 'active', 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $this->actingAs($employee, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/attendance/clock-in', ['store_id' => $store->uuid])->assertCreated();
        $this->actingAs($employee, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/attendance/clock-in', ['store_id' => $store->uuid])->assertStatus(409);
        $record = $profile->attendance()->firstOrFail();
        $this->actingAs($employee, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/attendance/'.$record->uuid.'/clock-out')->assertOk();
        $this->assertNotNull($record->fresh()->clock_out);
    }

    public function test_payroll_requires_admin_and_net_salary_is_calculated_without_payment(): void
    {
        [$tenant, $admin, $employee] = $this->fixture('payroll');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'employee_number' => 'EMP-004']);
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $payload = ['period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'base_salary' => 500000, 'allowances' => 25000, 'deductions' => 10000];
        $this->actingAs($employee, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/payroll', $payload)->assertForbidden();
        $response = $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/payroll', $payload)->assertCreated();
        $response->assertJsonPath('data.net_salary', '515000.00')->assertJsonPath('data.status', 'pending_approval');
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/payroll', $payload)->assertStatus(409);
        $this->assertDatabaseMissing('payments', ['amount' => 515000]);
    }

    public function test_leave_requests_are_approved_by_admin_and_overlaps_are_rejected(): void
    {
        [$tenant, $admin, $employee] = $this->fixture('leave');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'employee_number' => 'EMP-006']);
        $headers = ['X-Tenant-Id' => $tenant->uuid];
        $payload = ['leave_type' => 'annual', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-03', 'reason' => 'Family leave'];
        $leave = $this->actingAs($employee, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/leave', $payload)->assertCreated()->json('data.id');
        $this->actingAs($employee, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/'.$profile->uuid.'/leave', ['leave_type' => 'sick', 'starts_on' => '2026-11-03', 'ends_on' => '2026-11-04'])->assertStatus(409);
        $this->actingAs($admin, 'sanctum')->withHeaders($headers)->postJson('/api/v1/business/employees/leave/'.$leave.'/review', ['status' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_commission_reversal_creates_negative_entry_and_keeps_original_immutable(): void
    {
        [$tenant, $admin, $employee] = $this->fixture('commission');
        $profile = EmployeeProfile::create(['tenant_id' => $tenant->id, 'user_id' => $employee->id, 'employee_number' => 'EMP-005']);
        $commission = EmployeeCommission::create(['tenant_id' => $tenant->id, 'employee_profile_id' => $profile->id, 'commission_rate' => 5, 'base_amount' => 100000, 'commission_amount' => 5000, 'source_reference' => 'sale:commission-1', 'created_by' => $admin->id]);
        $this->actingAs($admin, 'sanctum')->withHeaders(['X-Tenant-Id' => $tenant->uuid])->postJson('/api/v1/business/employees/commissions/'.$commission->uuid.'/reverse', ['reason' => 'Refunded sale'])->assertOk();
        $this->assertDatabaseHas('employee_commissions', ['id' => $commission->id, 'commission_amount' => 5000, 'status' => 'accrued']);
        $this->assertDatabaseHas('employee_commissions', ['employee_profile_id' => $profile->id, 'commission_amount' => -5000, 'status' => 'adjusted']);
    }

    private function fixture(string $suffix): array
    {
        $slug = 'employee-'.$suffix;
        $tenant = Tenant::create(['name' => 'Employee '.$suffix, 'slug' => $slug, 'business_code' => strtoupper(substr(md5($slug), 0, 10)), 'status' => 'active', 'subscription_status' => 'active']);
        $admin = $this->userWithMembership($tenant, 'employee-admin-'.$suffix.'@example.com', 'business_admin');
        $employee = $this->userWithMembership($tenant, 'employee-user-'.$suffix.'@example.com', 'salesperson');
        $store = Store::create(['tenant_id' => $tenant->id, 'name' => 'Employee Store '.$suffix, 'code' => 'EMP-'.strtoupper(substr(md5($slug), 0, 8)), 'status' => 'active', 'currency_code' => 'MMK', 'timezone' => 'Asia/Yangon', 'created_by' => $admin->id]);
        return [$tenant, $admin, $employee, $store];
    }

    private function userWithMembership(Tenant $tenant, string $email, string $roleName): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'name' => $roleName]);
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make('CorrectPassword1!')]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        return $user;
    }
}
