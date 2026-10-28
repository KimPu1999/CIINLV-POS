<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AttendanceRecord;
use App\Models\EmployeeCommission;
use App\Models\EmployeeDocument;
use App\Models\EmployeePayrollRecord;
use App\Models\EmployeeProfile;
use App\Models\EmployeeBranchAssignment;
use App\Models\LeaveRequest;
use App\Models\PosRegisterMembership;
use App\Models\Sale;
use App\Models\StoreMembership;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BusinessMembershipService;
use App\Services\StoreMembershipService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class EmployeeManagementController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorizeTenantView($request->user(), $tenant->id);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,on_leave,suspended,terminated'],
            'branch_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'in:10,20,50,100'],
        ]);
        $query = EmployeeProfile::query()->where('tenant_id', $tenant->id)->with(['user', 'user.storeMemberships' => fn ($query) => $query->where('tenant_id', $tenant->id)->where('status', 'active')->whereNull('revoked_at')->with('store'), 'branchAssignments' => fn ($query) => $query->where('status', 'active')->with('store')]);
        $this->scopeEmployeesForUser($query, $request->user(), $tenant->id);

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('employee_number', 'ilike', "%{$search}%")
                    ->orWhere('job_title', 'ilike', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"));
            });
        }
        if (! empty($validated['status'])) {
            $query->where('employment_status', $validated['status']);
        }
        if (! empty($validated['branch_id'])) {
            $branch = $tenant->stores()->where('uuid', $validated['branch_id'])->firstOrFail();
            $query->whereHas('user.storeMemberships', fn ($membership) => $membership->where('tenant_id', $tenant->id)->where('store_id', $branch->id)->where('status', 'active')->whereNull('revoked_at'));
        }

        $paginator = $query->orderBy('employee_number')->paginate($validated['per_page'] ?? 20);
        $paginator->through(fn (EmployeeProfile $employee): array => $this->employeeData($employee));

        return response()->json(['success' => true, 'data' => $paginator]);
    }

    public function store(Request $request, TenantContext $context, AuditLogger $audit, StoreMembershipService $memberships, BusinessMembershipService $businessMemberships): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorizeTenantManage($request->user(), $tenant->id);
        $data = $request->validate($this->profileRules(true));
        $data['account_mode'] = $data['account_mode'] ?? (! empty($data['user_id']) ? 'link_existing' : 'record_only');
        if ($data['account_mode'] === 'create_login' && blank($data['name'] ?? null)) throw new UnprocessableEntityHttpException('Employee name is required to create a login invitation.');
        $user = null;
        if ($data['account_mode'] === 'link_existing') {
            $user = $tenant->users()->whereKey($data['user_id'])->wherePivot('status', 'active')->first();
            if (! $user || ! $user->isActive()) throw new UnprocessableEntityHttpException('Select an active user who already belongs to this business.');
            $data['name'] = $data['name'] ?? $user->name;
        } elseif ($data['account_mode'] === 'create_login') {
            [$user] = $businessMemberships->inviteMember($tenant, $data['name'], $data['login_email'], $data['account_role'], $request->user());
        }
        if (blank($data['name'] ?? null)) throw new UnprocessableEntityHttpException('Employee name is required.');
        if ($user && EmployeeProfile::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
            throw new ConflictHttpException('This user already has an employee profile in this business.');
        }

        $profile = DB::transaction(function () use ($request, $tenant, $data, $user, $audit, $memberships): EmployeeProfile {
            $profile = EmployeeProfile::create(array_merge($this->profileAttributes($data), [
                'tenant_id' => $tenant->id,
                'user_id' => $user?->id,
                'name' => $data['name'],
                'created_by' => $request->user()->id,
            ]));
            $this->storePhoto($request, $profile, $tenant->id);
            $this->assignBranchIfRequested($data, $tenant, $profile, $user, $request->user(), $memberships);
            $audit->record('employee.profile_created', $request->user(), $tenant, $profile, ['user_id' => $user?->id, 'employee_number' => $profile->employee_number, 'account_mode' => $data['account_mode']]);
            return $profile->fresh(['user', 'user.storeMemberships.store', 'branchAssignments.store']);
        });

        return response()->json(['success' => true, 'message' => 'Employee profile created.', 'data' => $this->employeeData($profile)], 201);
    }

    public function show(EmployeeProfile $employee, Request $request, TenantContext $context): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeEmployeeView($request->user(), $employee);
        $employee->load(['user', 'user.storeMemberships' => fn ($query) => $query->where('tenant_id', $employee->tenant_id)->with('store'), 'documents' => fn ($query) => $query->latest(), 'attendance' => fn ($query) => $query->latest('work_date')->limit(20), 'leaveRequests' => fn ($query) => $query->latest(), 'commissions' => fn ($query) => $query->latest()]);
        $data = $this->employeeData($employee);
        $data['documents'] = $employee->documents->map(fn (EmployeeDocument $document): array => ['id' => $document->uuid, 'document_type' => $document->document_type, 'original_name' => $document->original_name, 'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes, 'created_at' => $document->created_at, 'download_url' => route('business.employee.documents.download', ['employee' => $employee->uuid, 'document' => $document->uuid], false)])->values();
        $data['attendance'] = $employee->attendance->map(fn (AttendanceRecord $record): array => $this->attendanceData($record))->values();
        $data['leave_requests'] = $employee->leaveRequests->map(fn (LeaveRequest $leave): array => $this->leaveData($leave))->values();
        if ($this->isBusinessAdmin($request->user(), $employee->tenant_id)) {
            $data['payroll'] = $employee->payrollRecords()->latest('period_end')->get()->map(fn (EmployeePayrollRecord $record): array => $this->payrollData($record))->values();
        }
        $data['commissions'] = $employee->commissions->map(fn (EmployeeCommission $commission): array => $this->commissionData($commission))->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update(Request $request, EmployeeProfile $employee, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeTenantManage($request->user(), $employee->tenant_id);
        $data = $request->validate($this->profileRules(false, $employee));
        $employee->update($this->profileAttributes($data));
        $this->storePhoto($request, $employee, $employee->tenant_id);
        $audit->record('employee.profile_updated', $request->user(), $context->tenant(), $employee, ['changed_fields' => array_keys($this->profileAttributes($data))]);

        return response()->json(['success' => true, 'message' => 'Employee profile updated.', 'data' => $this->employeeData($employee->fresh(['user', 'user.storeMemberships.store', 'branchAssignments.store']))]);
    }

    public function deactivate(EmployeeProfile $employee, Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeTenantManage($request->user(), $employee->tenant_id);
        DB::transaction(function () use ($employee, $request, $context, $audit): void {
            $locked = EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $locked->update(['employment_status' => 'terminated', 'deleted_at' => null]);
            StoreMembership::query()->where('tenant_id', $context->tenant()->id)->where('user_id', $locked->user_id)->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => now()]);
            PosRegisterMembership::query()->where('tenant_id', $context->tenant()->id)->where('user_id', $locked->user_id)->where('status', 'active')->update(['status' => 'revoked', 'revoked_at' => now()]);
            $audit->record('employee.access_revoked', $request->user(), $context->tenant(), $locked, ['user_id' => $locked->user_id]);
        });

        return response()->json(['success' => true, 'message' => 'Employee deactivated and branch/register access revoked.']);
    }

    public function attendanceIndex(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorizeTenantView($request->user(), $tenant->id);
        $data = $request->validate(['employee_id' => ['nullable', 'string'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'in:10,20,50,100']]);
        $query = AttendanceRecord::query()->where('tenant_id', $tenant->id)->with(['employee.user', 'store'])->latest('work_date');
        $this->scopeAttendanceForUser($query, $request->user(), $tenant->id);
        if (! empty($data['employee_id'])) $query->whereHas('employee', fn ($employee) => $employee->where('uuid', $data['employee_id']));
        if (! empty($data['from'])) $query->whereDate('work_date', '>=', $data['from']);
        if (! empty($data['to'])) $query->whereDate('work_date', '<=', $data['to']);
        $records = $query->paginate($data['per_page'] ?? 20);
        $records->through(fn (AttendanceRecord $record): array => $this->attendanceData($record));
        return response()->json(['success' => true, 'data' => $records]);
    }

    public function clockIn(Request $request, EmployeeProfile $employee, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeEmployeeWrite($request->user(), $employee);
        $data = $request->validate(['store_id' => ['nullable', 'string'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $storeId = $this->storeId($data['store_id'] ?? null, $context);
        $this->assertEmployeeStoreAccess($request->user(), $employee, $storeId);
        $record = DB::transaction(function () use ($employee, $context, $request, $storeId, $data): AttendanceRecord {
            $record = AttendanceRecord::query()->where('employee_profile_id', $employee->id)->whereDate('work_date', now($this->timezone($storeId))->toDateString())->lockForUpdate()->first();
            if ($record?->clock_in) throw new ConflictHttpException('This employee is already clocked in today.');
            $record ??= new AttendanceRecord(['tenant_id' => $context->tenant()->id, 'employee_profile_id' => $employee->id, 'work_date' => now($this->timezone($storeId))->toDateString(), 'created_by' => $request->user()->id]);
            $record->fill(['store_id' => $storeId, 'clock_in' => now($this->timezone($storeId)), 'status' => 'present', 'notes' => $data['notes'] ?? $record->notes]);
            $record->save();
            return $record;
        });
        $audit->record('employee.attendance_clocked_in', $request->user(), $context->tenant(), $record, ['employee_id' => $employee->uuid]);
        return response()->json(['success' => true, 'data' => $this->attendanceData($record->fresh(['employee.user', 'store']))], 201);
    }

    public function clockOut(Request $request, AttendanceRecord $attendance, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($attendance, $context);
        $attendance->load('employee');
        $this->authorizeEmployeeWrite($request->user(), $attendance->employee);
        $record = DB::transaction(function () use ($attendance): AttendanceRecord {
            $record = AttendanceRecord::query()->whereKey($attendance->id)->lockForUpdate()->firstOrFail();
            if (! $record->clock_in) throw new UnprocessableEntityHttpException('Clock in before clocking out.');
            if ($record->clock_out) throw new ConflictHttpException('This attendance record is already closed.');
            $record->update(['clock_out' => now()]);
            return $record;
        });
        $audit->record('employee.attendance_clocked_out', $request->user(), $context->tenant(), $record, ['employee_id' => $attendance->employee->uuid]);
        return response()->json(['success' => true, 'data' => $this->attendanceData($record->fresh(['employee.user', 'store']))]);
    }

    public function leaveIndex(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorizeTenantView($request->user(), $tenant->id);
        $query = LeaveRequest::query()->where('tenant_id', $tenant->id)->with('employee.user')->latest();
        if (! $this->isBusinessAdmin($request->user(), $tenant->id)) $query->whereHas('employee.user.storeMemberships', fn ($membership) => $membership->where('user_id', $request->user()->id)->where('tenant_id', $tenant->id)->where('status', 'active')->whereNull('revoked_at'));
        $records = $query->paginate($request->validate(['per_page' => ['nullable', 'integer', 'in:10,20,50,100']])['per_page'] ?? 20);
        $records->through(fn (LeaveRequest $leave): array => $this->leaveData($leave));
        return response()->json(['success' => true, 'data' => $records]);
    }

    public function storeLeave(Request $request, EmployeeProfile $employee, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeEmployeeWrite($request->user(), $employee);
        $data = $request->validate(['leave_type' => ['required', 'string', 'max:50'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'reason' => ['nullable', 'string', 'max:2000']]);
        if (LeaveRequest::query()->where('employee_profile_id', $employee->id)->whereIn('status', ['pending', 'approved'])->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists()) throw new ConflictHttpException('This leave period overlaps an existing request.');
        $leave = LeaveRequest::create(array_merge($data, ['tenant_id' => $context->tenant()->id, 'employee_profile_id' => $employee->id, 'created_by' => $request->user()->id]));
        $audit->record('employee.leave_requested', $request->user(), $context->tenant(), $leave, ['employee_id' => $employee->uuid]);
        return response()->json(['success' => true, 'data' => $this->leaveData($leave->load('employee.user'))], 201);
    }

    public function reviewLeave(Request $request, LeaveRequest $leave, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($leave, $context);
        $leave->load('employee');
        $this->authorizeTenantManage($request->user(), $leave->tenant_id);
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $leave->update(['status' => $data['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $audit->record('employee.leave_'.$data['status'], $request->user(), $context->tenant(), $leave, ['employee_id' => $leave->employee->uuid]);
        return response()->json(['success' => true, 'data' => $this->leaveData($leave->fresh('employee.user'))]);
    }

    public function payrollIndex(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorizeTenantManage($request->user(), $tenant->id);
        $query = EmployeePayrollRecord::query()->where('tenant_id', $tenant->id)->with('employee.user')->latest('period_end');
        if ($request->filled('employee_id')) $query->whereHas('employee', fn ($employee) => $employee->where('uuid', $request->string('employee_id')));
        $records = $query->paginate($request->validate(['per_page' => ['nullable', 'integer', 'in:10,20,50,100']])['per_page'] ?? 20);
        $records->through(fn (EmployeePayrollRecord $record): array => $this->payrollData($record));
        return response()->json(['success' => true, 'data' => $records]);
    }

    public function storePayroll(Request $request, EmployeeProfile $employee, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeTenantManage($request->user(), $employee->tenant_id);
        $data = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date', 'after_or_equal:period_start'], 'base_salary' => ['required', 'numeric', 'min:0'], 'allowances' => ['nullable', 'numeric', 'min:0'], 'deductions' => ['nullable', 'numeric', 'min:0'], 'currency_code' => ['nullable', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (EmployeePayrollRecord::query()->where('employee_profile_id', $employee->id)->where('period_start', $data['period_start'])->where('period_end', $data['period_end'])->exists()) throw new ConflictHttpException('Payroll for this employee and period already exists.');
        $net = round((float) $data['base_salary'] + (float) ($data['allowances'] ?? 0) - (float) ($data['deductions'] ?? 0), 2);
        if ($net < 0) throw new UnprocessableEntityHttpException('Deductions cannot exceed gross salary.');
        $payroll = EmployeePayrollRecord::create(array_merge($data, ['tenant_id' => $context->tenant()->id, 'employee_profile_id' => $employee->id, 'allowances' => $data['allowances'] ?? 0, 'deductions' => $data['deductions'] ?? 0, 'net_salary' => $net, 'currency_code' => strtoupper($data['currency_code'] ?? 'MMK'), 'status' => 'pending_approval']));
        $audit->record('employee.payroll_created', $request->user(), $context->tenant(), $payroll, ['employee_id' => $employee->uuid, 'net_salary' => $net]);
        return response()->json(['success' => true, 'data' => $this->payrollData($payroll->load('employee.user'))], 201);
    }

    public function approvePayroll(Request $request, EmployeePayrollRecord $payroll, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($payroll, $context);
        $this->authorizeTenantManage($request->user(), $payroll->tenant_id);
        if ($payroll->status !== 'pending_approval') throw new ConflictHttpException('Only payroll awaiting approval can be approved.');
        $payroll->update(['status' => 'approved', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        $audit->record('employee.payroll_approved', $request->user(), $context->tenant(), $payroll, ['employee_id' => $payroll->employee_profile_id]);
        return response()->json(['success' => true, 'data' => $this->payrollData($payroll->fresh('employee.user'))]);
    }

    public function commissionIndex(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $this->authorizeTenantView($request->user(), $tenant->id);
        $query = EmployeeCommission::query()->where('tenant_id', $tenant->id)->with('employee.user')->latest();
        if (! $this->isBusinessAdmin($request->user(), $tenant->id)) $query->whereHas('employee.user.storeMemberships', fn ($membership) => $membership->where('user_id', $request->user()->id)->where('tenant_id', $tenant->id));
        $records = $query->paginate($request->validate(['per_page' => ['nullable', 'integer', 'in:10,20,50,100']])['per_page'] ?? 20);
        $records->through(fn (EmployeeCommission $commission): array => $this->commissionData($commission));
        return response()->json(['success' => true, 'data' => $records]);
    }

    public function storeCommission(Request $request, EmployeeProfile $employee, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeTenantManage($request->user(), $employee->tenant_id);
        $data = $request->validate(['sale_id' => ['nullable', 'integer'], 'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'base_amount' => ['required', 'numeric', 'min:0'], 'source_reference' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:accrued,void']]);
        if (! empty($data['sale_id'])) {
            $sale = Sale::query()->where('tenant_id', $context->tenant()->id)->whereKey($data['sale_id'])->first();
            if (! $sale || (int) $sale->salesperson_user_id !== (int) $employee->user_id) throw new UnprocessableEntityHttpException('The selected sale is not assigned to this employee in this business.');
        }
        if (! empty($data['source_reference']) && EmployeeCommission::where('tenant_id', $context->tenant()->id)->where('source_reference', $data['source_reference'])->exists()) throw new ConflictHttpException('This commission reference has already been recorded.');
        $commission = EmployeeCommission::create(array_merge($data, ['tenant_id' => $context->tenant()->id, 'employee_profile_id' => $employee->id, 'commission_amount' => round((float) $data['base_amount'] * (float) $data['commission_rate'] / 100, 2), 'created_by' => $request->user()->id, 'status' => $data['status'] ?? 'accrued']));
        $audit->record('employee.commission_created', $request->user(), $context->tenant(), $commission, ['employee_id' => $employee->uuid]);
        return response()->json(['success' => true, 'data' => $this->commissionData($commission->load('employee.user'))], 201);
    }

    public function reverseCommission(Request $request, EmployeeCommission $commission, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($commission, $context);
        $this->authorizeTenantManage($request->user(), $commission->tenant_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $reversal = DB::transaction(function () use ($commission, $data, $request): EmployeeCommission {
            $original = EmployeeCommission::query()->whereKey($commission->id)->lockForUpdate()->firstOrFail();
            $reference = 'reversal:'.$original->uuid;
            $existing = EmployeeCommission::where('tenant_id', $original->tenant_id)->where('source_reference', $reference)->first();
            if ($existing) return $existing;
            return EmployeeCommission::create(['tenant_id' => $original->tenant_id, 'employee_profile_id' => $original->employee_profile_id, 'sale_id' => $original->sale_id, 'commission_rate' => $original->commission_rate, 'base_amount' => -abs((float) $original->base_amount), 'commission_amount' => -abs((float) $original->commission_amount), 'status' => 'adjusted', 'source_reference' => $reference, 'adjustment_reason' => $data['reason'], 'created_by' => $request->user()->id]);
        });
        $audit->record('employee.commission_reversed', $request->user(), $context->tenant(), $reversal, ['original_id' => $commission->uuid, 'reason' => $data['reason']]);
        return response()->json(['success' => true, 'data' => $this->commissionData($reversal->load('employee.user'))]);
    }

    public function uploadDocument(Request $request, EmployeeProfile $employee, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($employee, $context);
        $this->authorizeTenantManage($request->user(), $employee->tenant_id);
        $data = $request->validate(['document_type' => ['required', 'string', 'max:50'], 'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']]);
        $file = $data['document'];
        $document = EmployeeDocument::create(['tenant_id' => $context->tenant()->id, 'employee_profile_id' => $employee->id, 'document_type' => $data['document_type'], 'original_name' => $file->getClientOriginalName(), 'path' => $file->store('employees/'.$employee->id.'/documents', 'private'), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size_bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
        $audit->record('employee.document_uploaded', $request->user(), $context->tenant(), $document, ['employee_id' => $employee->uuid, 'document_type' => $document->document_type]);
        return response()->json(['success' => true, 'data' => ['id' => $document->uuid, 'original_name' => $document->original_name]], 201);
    }

    public function photo(EmployeeProfile $employee, Request $request, TenantContext $context): mixed
    {
        $this->assertTenant($employee, $context);
        $this->authorizeEmployeeView($request->user(), $employee);
        abort_unless($employee->photo_path && Storage::disk('private')->exists($employee->photo_path), 404);
        return response()->file(Storage::disk('private')->path($employee->photo_path));
    }

    public function downloadDocument(EmployeeProfile $employee, EmployeeDocument $document, Request $request, TenantContext $context): StreamedResponse
    {
        $this->assertTenant($employee, $context);
        if ((int) $document->employee_profile_id !== (int) $employee->id || (int) $document->tenant_id !== (int) $employee->tenant_id) throw new NotFoundHttpException('The requested employee document was not found.');
        $this->authorizeTenantManage($request->user(), $employee->tenant_id);
        abort_unless(Storage::disk('private')->exists($document->path), 404);
        return Storage::disk('private')->download($document->path, $document->original_name);
    }

    private function profileRules(bool $creating, ?EmployeeProfile $employee = null): array
    {
        return ['account_mode' => ['sometimes', 'in:record_only,link_existing,create_login'], 'name' => ['nullable', 'string', 'max:160'], 'user_id' => ['nullable', 'integer', 'exists:users,id'], 'login_email' => ['required_if:account_mode,create_login', 'nullable', 'email', 'max:255'], 'account_role' => ['required_if:account_mode,create_login', 'nullable', 'in:sales_staff,salesperson,cashier,inventory_manager'], 'employee_number' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'], 'job_title' => ['nullable', 'string', 'max:120'], 'department' => ['nullable', 'string', 'max:120'], 'employment_type' => ['nullable', 'in:full_time,part_time,contract,temporary'], 'hire_date' => ['nullable', 'date'], 'employment_status' => ['nullable', 'in:active,on_leave,suspended,terminated'], 'phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:500'], 'emergency_contact_name' => ['nullable', 'string', 'max:120'], 'emergency_contact_phone' => ['nullable', 'string', 'max:50'], 'notes' => ['nullable', 'string', 'max:2000'], 'branch_id' => ['nullable', 'string'], 'branch_role' => ['nullable', 'in:store_manager,inventory_manager,cashier,salesperson,sales_staff'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']];
    }

    private function profileAttributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['employee_number', 'job_title', 'department', 'employment_type', 'hire_date', 'employment_status', 'phone', 'address', 'emergency_contact_name', 'emergency_contact_phone', 'notes', 'name']));
    }

    private function employeeData(EmployeeProfile $employee): array
    {
        $userBranches = $employee->user?->storeMemberships?->map(fn (StoreMembership $membership): array => ['membership_id' => $membership->id, 'id' => $membership->store?->uuid, 'name' => $membership->store?->name, 'role' => $membership->role, 'status' => $membership->status])->filter(fn (array $branch): bool => $branch['id'] !== null)->values()->all() ?? [];
        $employeeBranches = $employee->branchAssignments?->map(fn (EmployeeBranchAssignment $assignment): array => ['membership_id' => null, 'id' => $assignment->store?->uuid, 'name' => $assignment->store?->name, 'role' => $assignment->role, 'status' => $assignment->status])->filter(fn (array $branch): bool => $branch['id'] !== null)->values()->all() ?? [];
        return ['id' => $employee->uuid, 'name' => $employee->name ?: $employee->user?->name, 'employee_number' => $employee->employee_number, 'job_title' => $employee->job_title, 'department' => $employee->department, 'employment_type' => $employee->employment_type, 'hire_date' => $employee->hire_date?->toDateString(), 'employment_status' => $employee->employment_status, 'phone' => $employee->phone, 'address' => $employee->address, 'emergency_contact_name' => $employee->emergency_contact_name, 'emergency_contact_phone' => $employee->emergency_contact_phone, 'notes' => $employee->notes, 'photo_available' => (bool) $employee->photo_path, 'photo_url' => $employee->photo_path ? route('business.employee.photo', ['employee' => $employee->uuid], false) : null, 'user' => $employee->user ? ['id' => $employee->user->id, 'name' => $employee->user->name, 'email' => $employee->user->email, 'status' => $employee->user->status, 'profile_image_url' => $employee->user->profile_image_path ? route('me.profile-image', [], false) : null] : null, 'branches' => collect($userBranches)->merge($employeeBranches)->unique('id')->values()->all()];
    }

    private function attendanceData(AttendanceRecord $record): array { return ['id' => $record->uuid, 'employee_id' => $record->employee?->uuid, 'employee_name' => $record->employee?->user?->name, 'store' => $record->store?->name, 'work_date' => $record->work_date?->toDateString(), 'clock_in' => $record->clock_in?->toIso8601String(), 'clock_out' => $record->clock_out?->toIso8601String(), 'status' => $record->status, 'overtime_minutes' => $record->overtime_minutes, 'notes' => $record->notes]; }
    private function leaveData(LeaveRequest $leave): array { return ['id' => $leave->uuid, 'employee_id' => $leave->employee?->uuid, 'employee_name' => $leave->employee?->user?->name, 'leave_type' => $leave->leave_type, 'starts_on' => $leave->starts_on?->toDateString(), 'ends_on' => $leave->ends_on?->toDateString(), 'reason' => $leave->reason, 'status' => $leave->status, 'reviewed_at' => $leave->reviewed_at?->toIso8601String()]; }
    private function payrollData(EmployeePayrollRecord $record): array { return ['id' => $record->uuid, 'employee_id' => $record->employee?->uuid, 'employee_name' => $record->employee?->user?->name, 'period_start' => $record->period_start?->toDateString(), 'period_end' => $record->period_end?->toDateString(), 'base_salary' => $record->base_salary, 'allowances' => $record->allowances, 'deductions' => $record->deductions, 'net_salary' => $record->net_salary, 'currency_code' => $record->currency_code, 'status' => $record->status, 'approved_at' => $record->approved_at?->toIso8601String()]; }
    private function commissionData(EmployeeCommission $commission): array { return ['id' => $commission->uuid, 'employee_id' => $commission->employee?->uuid, 'employee_name' => $commission->employee?->user?->name, 'sale_id' => $commission->sale_id, 'commission_rate' => $commission->commission_rate, 'base_amount' => $commission->base_amount, 'commission_amount' => $commission->commission_amount, 'status' => $commission->status, 'source_reference' => $commission->source_reference, 'adjustment_reason' => $commission->adjustment_reason, 'created_at' => $commission->created_at?->toIso8601String()]; }

    private function assignBranchIfRequested(array $data, $tenant, EmployeeProfile $employee, ?User $user, User $actor, StoreMembershipService $memberships): void
    {
        if (! empty($data['branch_id'])) {
            $store = $tenant->stores()->where('uuid', $data['branch_id'])->firstOrFail();
            EmployeeBranchAssignment::updateOrCreate(['employee_profile_id' => $employee->id, 'store_id' => $store->id], ['tenant_id' => $tenant->id, 'role' => $data['branch_role'] ?? 'sales_staff', 'status' => 'active', 'assigned_by' => $actor->id, 'assigned_at' => now(), 'revoked_at' => null]);
            if ($user?->isActive() && $data['account_mode'] === 'link_existing' && ! StoreMembership::query()->where('tenant_id', $tenant->id)->where('store_id', $store->id)->where('user_id', $user->id)->where('status', 'active')->whereNull('revoked_at')->exists()) $memberships->assign($store, ['user_id' => $user->id, 'role' => $data['branch_role'] ?? 'sales_staff'], $actor);
        }
    }

    private function storePhoto(Request $request, EmployeeProfile $employee, int $tenantId): void
    {
        if (! $request->hasFile('photo')) return;
        if ($employee->photo_path) Storage::disk('private')->delete($employee->photo_path);
        $employee->update(['photo_path' => $request->file('photo')->store('employees/'.$tenantId.'/photos', 'private')]);
    }

    private function storeId(?string $uuid, TenantContext $context): ?int
    {
        if (! $uuid) return null;
        return $context->tenant()->stores()->where('uuid', $uuid)->value('id') ?: throw new NotFoundHttpException('The requested branch was not found.');
    }

    private function timezone(?int $storeId): string { return $storeId ? (string) DB::table('stores')->where('id', $storeId)->value('timezone') ?: 'Asia/Yangon' : 'Asia/Yangon'; }

    private function assertTenant(object $model, TenantContext $context): void { if ((int) $model->tenant_id !== (int) $context->tenant()->id) throw new NotFoundHttpException('The requested employee record was not found.'); }

    private function authorizeTenantView(User $user, int $tenantId): void { if (! $this->isBusinessAdmin($user, $tenantId) && ! $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($query) => $query->whereIn('name', ['store_manager', 'inventory_manager']))->exists()) throw new AccessDeniedHttpException('You are not authorized to view employee records.'); }
    private function authorizeTenantManage(User $user, int $tenantId): void { if (! $this->isBusinessAdmin($user, $tenantId)) throw new AccessDeniedHttpException('Only business administrators can manage employee records.'); }
    private function isBusinessAdmin(User $user, int $tenantId): bool { return $user->isSuperAdmin() || $user->memberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereHas('role', fn ($query) => $query->where('name', 'business_admin'))->exists(); }
    private function authorizeEmployeeView(User $user, EmployeeProfile $employee): void { if ($this->isBusinessAdmin($user, $employee->tenant_id) || (int) $employee->user_id === (int) $user->id || $this->employeeSharesStore($user, $employee)) return; throw new AccessDeniedHttpException('You are not authorized to view this employee.'); }
    private function authorizeEmployeeWrite(User $user, EmployeeProfile $employee): void { if ($this->isBusinessAdmin($user, $employee->tenant_id) || (int) $employee->user_id === (int) $user->id || $this->employeeSharesStoreAsManager($user, $employee)) return; throw new AccessDeniedHttpException('You are not authorized to update this employee record.'); }
    private function employeeSharesStore(User $user, EmployeeProfile $employee): bool { if (! $employee->user_id) return $employee->branchAssignments()->whereIn('store_id', $user->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id'))->where('status', 'active')->exists(); return $employee->user->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('status', 'active')->whereNull('revoked_at')->whereIn('store_id', $user->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id'))->exists(); }
    private function employeeSharesStoreAsManager(User $user, EmployeeProfile $employee): bool { $employeeStoreIds = $employee->user_id ? $employee->user->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id') : $employee->branchAssignments()->where('status', 'active')->pluck('store_id'); return $user->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('role', 'store_manager')->where('status', 'active')->whereNull('revoked_at')->whereIn('store_id', $employeeStoreIds)->exists(); }
    private function assertEmployeeStoreAccess(User $actor, EmployeeProfile $employee, ?int $storeId): void { if ($storeId === null || $this->isBusinessAdmin($actor, $employee->tenant_id)) return; if ((int) $actor->id === (int) $employee->user_id && $employee->user->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('store_id', $storeId)->where('status', 'active')->whereNull('revoked_at')->exists()) return; if ($this->employeeSharesStoreAsManager($actor, $employee) && $actor->storeMemberships()->where('tenant_id', $employee->tenant_id)->where('store_id', $storeId)->where('role', 'store_manager')->where('status', 'active')->whereNull('revoked_at')->exists()) return; throw new AccessDeniedHttpException('The employee is not assigned to the selected branch.'); }
    private function scopeEmployeesForUser($query, User $user, int $tenantId): void { if ($this->isBusinessAdmin($user, $tenantId)) return; $storeIds = $user->storeMemberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id'); $query->where(fn ($scope) => $scope->whereHas('user.storeMemberships', fn ($membership) => $membership->where('tenant_id', $tenantId)->whereIn('store_id', $storeIds)->where('status', 'active')->whereNull('revoked_at'))->orWhereHas('branchAssignments', fn ($assignment) => $assignment->whereIn('store_id', $storeIds)->where('status', 'active'))); }
    private function scopeAttendanceForUser($query, User $user, int $tenantId): void { if ($this->isBusinessAdmin($user, $tenantId)) return; $storeIds = $user->storeMemberships()->where('tenant_id', $tenantId)->where('status', 'active')->whereNull('revoked_at')->pluck('store_id'); $query->where(function ($builder) use ($storeIds, $user): void { $builder->whereIn('store_id', $storeIds)->orWhereHas('employee', fn ($employee) => $employee->where('user_id', $user->id)); }); }
}
