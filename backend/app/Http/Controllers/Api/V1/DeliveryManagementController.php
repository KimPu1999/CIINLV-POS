<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DeliveryAssignmentEvent;
use App\Models\DeliveryCourierPartner;
use App\Models\DeliveryStaffProfile;
use App\Models\EmployeeProfile;
use App\Models\MarketplaceShipment;
use App\Models\MarketplaceVendorOrder;
use App\Services\AuditLogger;
use App\Services\MarketplaceFulfillmentService;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class DeliveryManagementController
{
    private const PAGE_SIZES = [10, 20, 50, 100];
    private const TERMINAL_SHIPMENT_STATUSES = ['delivered', 'delivery_failed', 'returned', 'cancelled'];

    public function dashboard(TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $activeStaff = DeliveryStaffProfile::query()->where('tenant_id', $tenantId)->where('status', 'active');
        $shipments = MarketplaceShipment::query()->where('tenant_id', $tenantId);
        $eligible = MarketplaceVendorOrder::query()->where('tenant_id', $tenantId)->whereIn('status', ['pending', 'processing'])->whereHas('order', fn (Builder $order) => $order->whereIn('status', ['confirmed', 'processing']))->whereDoesntHave('shipments')->count();

        return response()->json(['success' => true, 'data' => [
            'staff' => [
                'total' => (clone $activeStaff)->count(),
                'available' => (clone $activeStaff)->where('availability_status', 'available')->count(),
                'busy' => (clone $activeStaff)->where('availability_status', 'busy')->count(),
                'off_duty' => (clone $activeStaff)->where('availability_status', 'off_duty')->count(),
            ],
            'deliveries' => [
                'pending_assignment' => (clone $shipments)->where('assignment_status', 'pending_assignment')->whereNotIn('status', self::TERMINAL_SHIPMENT_STATUSES)->count(),
                'active' => (clone $shipments)->whereNotIn('status', self::TERMINAL_SHIPMENT_STATUSES)->count(),
                'delivered_today' => (clone $shipments)->where('status', 'delivered')->whereDate('delivered_at', now()->toDateString())->count(),
                'failed' => (clone $shipments)->where('status', 'delivery_failed')->count(),
            ],
            'eligible_orders' => $eligible,
        ]]);
    }

    public function lookup(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant();
        $employees = EmployeeProfile::query()
            ->where('tenant_id', $tenant->id)
            ->where('employment_status', 'active')
            ->with(['user', 'deliveryStaff'])
            ->whereDoesntHave('deliveryStaff')
            ->orderBy('employee_number')
            ->get()
            ->map(fn (EmployeeProfile $employee): array => [
                'id' => $employee->uuid,
                'employee_number' => $employee->employee_number,
                'name' => $employee->user?->name ?: $employee->employee_number,
                'email' => $employee->user?->email,
            ])->values();

        $stores = $tenant->stores()->where('status', 'active')->orderBy('name')->get()->map(fn ($store): array => [
            'id' => $store->uuid, 'name' => $store->name, 'code' => $store->code,
        ])->values();

        $partners = DeliveryCourierPartner::query()->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get()->map(fn (DeliveryCourierPartner $partner): array => [
            'id' => $partner->uuid, 'name' => $partner->name, 'phone' => $partner->phone,
        ])->values();

        return response()->json(['success' => true, 'data' => compact('employees', 'stores', 'partners')]);
    }

    public function staffIndex(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'availability' => ['nullable', 'in:available,busy,off_duty'],
            'store_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'in:10,20,50,100'],
        ]);
        $query = DeliveryStaffProfile::query()->where('tenant_id', $context->tenant()->id)->with(['employee.user', 'store']);
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['availability'])) $query->where('availability_status', $data['availability']);
        if (! empty($data['store_id'])) $query->whereHas('store', fn (Builder $store) => $store->where('uuid', $data['store_id']));
        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('staff_code', 'ilike', $term)->orWhere('vehicle_plate', 'ilike', $term)->orWhereHas('employee', fn (Builder $employee) => $employee->whereHas('user', fn (Builder $user) => $user->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)));
            });
        }
        $paginator = $query->orderBy('staff_code')->paginate($data['per_page'] ?? 20);
        $paginator->through(fn (DeliveryStaffProfile $staff): array => $this->staffData($staff));
        return response()->json(['success' => true, 'data' => $paginator]);
    }

    public function staffStore(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = $context->tenant();
        $data = $request->validate($this->staffRules($tenant->id));
        $employee = EmployeeProfile::query()->where('tenant_id', $tenant->id)->where('uuid', $data['employee_profile_id'])->with('user')->firstOrFail();
        if ($employee->employment_status !== 'active' || ! $employee->user?->isActive()) throw new UnprocessableEntityHttpException('Only active employee profiles can be registered as delivery staff.');
        $storeId = $this->storeId($data['store_id'] ?? null, $tenant->id);
        if (DeliveryStaffProfile::query()->where('tenant_id', $tenant->id)->where('employee_profile_id', $employee->id)->exists()) throw new ConflictHttpException('This employee is already registered as delivery staff.');
        if (! empty($data['staff_code']) && DeliveryStaffProfile::query()->where('tenant_id', $tenant->id)->where('staff_code', $data['staff_code'])->exists()) throw new ConflictHttpException('This delivery staff ID is already in use.');
        $staff = DB::transaction(function () use ($request, $tenant, $employee, $storeId, $data, $audit): DeliveryStaffProfile {
            $staff = DeliveryStaffProfile::create([
                'tenant_id' => $tenant->id, 'employee_profile_id' => $employee->id, 'store_id' => $storeId,
                'staff_code' => $data['staff_code'] ?? $this->nextStaffCode($tenant->id),
                'vehicle_type' => $data['vehicle_type'] ?? null, 'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'availability_status' => $data['availability_status'] ?? 'available', 'status' => 'active', 'created_by' => $request->user()->id,
            ]);
            $audit->record('delivery.staff_created', $request->user(), $tenant, $staff, ['employee_id' => $employee->uuid, 'store_id' => $storeId]);
            return $staff->fresh(['employee.user', 'store']);
        });
        return response()->json(['success' => true, 'message' => 'Delivery staff registered.', 'data' => $this->staffData($staff)], 201);
    }

    public function staffUpdate(Request $request, DeliveryStaffProfile $staff, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($staff, $context);
        $data = $request->validate($this->staffRules($context->tenant()->id, false));
        $storeId = array_key_exists('store_id', $data) ? $this->storeId($data['store_id'], $context->tenant()->id) : $staff->store_id;
        if (! empty($data['staff_code']) && DeliveryStaffProfile::query()->where('tenant_id', $context->tenant()->id)->where('staff_code', $data['staff_code'])->where('id', '!=', $staff->id)->exists()) throw new ConflictHttpException('This delivery staff ID is already in use.');
        $staff->update(array_filter([
            'store_id' => $storeId, 'staff_code' => $data['staff_code'] ?? null, 'vehicle_type' => $data['vehicle_type'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null, 'availability_status' => $data['availability_status'] ?? null,
        ], fn ($value): bool => $value !== null));
        $audit->record('delivery.staff_updated', $request->user(), $context->tenant(), $staff, ['changed_fields' => array_keys($data)]);
        return response()->json(['success' => true, 'message' => 'Delivery staff updated.', 'data' => $this->staffData($staff->fresh(['employee.user', 'store']))]);
    }

    public function staffDeactivate(Request $request, DeliveryStaffProfile $staff, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($staff, $context);
        DB::transaction(function () use ($staff, $request, $context, $audit): void {
            $locked = DeliveryStaffProfile::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            if ($locked->shipments()->whereNotIn('status', self::TERMINAL_SHIPMENT_STATUSES)->exists()) throw new ConflictHttpException('Reassign active deliveries before deactivating this staff member.');
            $locked->update(['status' => 'inactive', 'availability_status' => 'off_duty']);
            $audit->record('delivery.staff_deactivated', $request->user(), $context->tenant(), $locked);
        });
        return response()->json(['success' => true, 'message' => 'Delivery staff deactivated.']);
    }

    public function staffAvailability(Request $request, DeliveryStaffProfile $staff, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($staff, $context);
        $data = $request->validate(['availability_status' => ['required', 'in:available,busy,off_duty']]);
        if ($staff->status !== 'active' && $data['availability_status'] === 'available') throw new UnprocessableEntityHttpException('Inactive delivery staff cannot be made available.');
        $staff->update(['availability_status' => $data['availability_status']]);
        $audit->record('delivery.staff_availability_updated', $request->user(), $context->tenant(), $staff, ['availability_status' => $data['availability_status']]);
        return response()->json(['success' => true, 'data' => $this->staffData($staff->fresh(['employee.user', 'store']))]);
    }

    public function eligibleOrders(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'in:10,20,50,100']]);
        $query = MarketplaceVendorOrder::query()->where('tenant_id', $context->tenant()->id)->whereIn('status', ['pending', 'processing'])->whereHas('order', fn (Builder $order) => $order->whereIn('status', ['confirmed', 'processing']))->whereDoesntHave('shipments')->with(['order.customer', 'store', 'items.reservations.balance.location.store']);
        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('vendor_order_number', 'ilike', $term)->orWhereHas('order', fn (Builder $order) => $order->where('order_number', 'ilike', $term)->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)));
            });
        }
        $paginator = $query->latest('created_at')->paginate($data['per_page'] ?? 20);
        $paginator->through(fn (MarketplaceVendorOrder $order): array => $this->orderData($order));
        return response()->json(['success' => true, 'data' => $paginator]);
    }

    public function assign(Request $request, TenantContext $context, MarketplaceFulfillmentService $fulfillment, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'string'], 'delivery_type' => ['required', 'in:self_delivery,external_courier,customer_pickup'],
            'staff_id' => ['nullable', 'string', 'required_if:delivery_type,self_delivery'], 'courier_partner_id' => ['nullable', 'string', 'required_if:delivery_type,external_courier'],
            'delivery_date' => ['nullable', 'date'], 'delivery_instructions' => ['nullable', 'string', 'max:2000'],
            'tracking_number' => ['nullable', 'string', 'max:150'], 'tracking_url' => ['nullable', 'url', 'regex:/^https?:\\/\\//i', 'max:500'],
        ]);
        $tenant = $context->tenant();
        $idempotencyKey = trim((string) ($request->header('Idempotency-Key') ?: $request->input('idempotency_key')));
        if ($idempotencyKey === '') throw new UnprocessableEntityHttpException('An idempotency key is required for delivery assignment.');
        $existing = MarketplaceShipment::query()->where('tenant_id', $tenant->id)->where('assignment_idempotency_key', $idempotencyKey)->with($this->shipmentRelations())->first();
        if ($existing) return response()->json(['success' => true, 'idempotent_replay' => true, 'data' => $this->shipmentData($existing)]);

        try {
            $shipment = DB::transaction(function () use ($request, $tenant, $data, $idempotencyKey, $fulfillment, $audit): MarketplaceShipment {
                $order = MarketplaceVendorOrder::query()->where('tenant_id', $tenant->id)->where('uuid', $data['order_id'])->with(['order', 'items.reservations.balance.location.store'])->lockForUpdate()->firstOrFail();
                if (strlen($idempotencyKey) > 120) throw new UnprocessableEntityHttpException('The idempotency key is too long.');
                if ($order->shipments()->exists()) throw new ConflictHttpException('This order already has a shipment assignment.');
                $staff = null;
                $partner = null;
                if ($data['delivery_type'] === 'self_delivery') {
                    $staff = DeliveryStaffProfile::query()->where('tenant_id', $tenant->id)->where('uuid', $data['staff_id'])->lockForUpdate()->firstOrFail();
                    if ($staff->status !== 'active' || $staff->availability_status !== 'available') throw new ConflictHttpException('The selected delivery staff member is not available.');
                    $branches = $order->branchAttributionData()['branches'];
                    if ($staff->store_id && $branches !== [] && ! in_array((int) $staff->store_id, array_map('intval', array_column($branches, 'key')), true)) throw new ConflictHttpException('The selected delivery staff member is assigned to a different branch than this order.');
                }
                if ($data['delivery_type'] === 'external_courier') {
                    $partner = DeliveryCourierPartner::query()->where('tenant_id', $tenant->id)->where('uuid', $data['courier_partner_id'])->where('status', 'active')->firstOrFail();
                }
                $shipment = $fulfillment->createShipment($order, $request->user(), [
                    'delivery_type' => $data['delivery_type'], 'carrier_name' => $partner?->name,
                    'tracking_number' => $data['tracking_number'] ?? null, 'tracking_url' => $data['tracking_url'] ?? null,
                    'delivery_notes' => $data['delivery_instructions'] ?? null,
                ]);
                $shipment->update([
                    'delivery_staff_profile_id' => $staff?->id, 'courier_partner_id' => $partner?->id, 'delivery_date' => $data['delivery_date'] ?? null,
                    'assignment_status' => 'assigned', 'assigned_at' => now(), 'assignment_idempotency_key' => $idempotencyKey,
                ]);
                DeliveryAssignmentEvent::create(['tenant_id' => $tenant->id, 'marketplace_shipment_id' => $shipment->id, 'delivery_staff_profile_id' => $staff?->id, 'event_type' => 'assigned', 'notes' => $data['delivery_instructions'] ?? null, 'actor_user_id' => $request->user()->id, 'event_time' => now(), 'metadata' => ['delivery_type' => $data['delivery_type'], 'courier_partner_id' => $partner?->uuid]]);
                if ($staff) $staff->update(['availability_status' => 'busy']);
                $audit->record('delivery.assignment_created', $request->user(), $tenant, $shipment, ['order_id' => $order->uuid, 'staff_id' => $staff?->uuid, 'delivery_type' => $data['delivery_type']]);
                return $shipment->fresh($this->shipmentRelations());
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'delivery_assignment_idempotency_unique')) {
                $shipment = MarketplaceShipment::query()->where('tenant_id', $tenant->id)->where('assignment_idempotency_key', $idempotencyKey)->with($this->shipmentRelations())->firstOrFail();
                return response()->json(['success' => true, 'idempotent_replay' => true, 'data' => $this->shipmentData($shipment)]);
            }
            throw $exception;
        }
        return response()->json(['success' => true, 'message' => 'Delivery assigned.', 'data' => $this->shipmentData($shipment)], 201);
    }

    public function active(Request $request, TenantContext $context): JsonResponse
    {
        return $this->shipmentList($request, $context, false);
    }

    public function history(Request $request, TenantContext $context): JsonResponse
    {
        return $this->shipmentList($request, $context, true);
    }

    public function reassign(Request $request, MarketplaceShipment $shipment, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($shipment, $context);
        $data = $request->validate(['staff_id' => ['required', 'string'], 'reason' => ['required', 'string', 'max:2000']]);
        $updated = DB::transaction(function () use ($request, $context, $shipment, $data, $audit): MarketplaceShipment {
            $locked = MarketplaceShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, self::TERMINAL_SHIPMENT_STATUSES, true)) throw new ConflictHttpException('Completed or cancelled deliveries cannot be reassigned.');
            if ($locked->delivery_type !== 'self_delivery') throw new ConflictHttpException('Only self-delivery assignments can be reassigned to a delivery staff member.');
            $newStaff = DeliveryStaffProfile::query()->where('tenant_id', $context->tenant()->id)->where('uuid', $data['staff_id'])->lockForUpdate()->firstOrFail();
            if ($newStaff->status !== 'active' || $newStaff->availability_status !== 'available') throw new ConflictHttpException('The selected delivery staff member is not available.');
            $previous = $locked->delivery_staff_profile_id;
            $locked->update(['delivery_staff_profile_id' => $newStaff->id, 'assignment_status' => 'assigned', 'assigned_at' => now()]);
            DeliveryAssignmentEvent::create(['tenant_id' => $context->tenant()->id, 'marketplace_shipment_id' => $locked->id, 'delivery_staff_profile_id' => $newStaff->id, 'previous_staff_profile_id' => $previous, 'event_type' => 'reassigned', 'reason' => $data['reason'], 'actor_user_id' => $request->user()->id, 'event_time' => now()]);
            $newStaff->update(['availability_status' => 'busy']);
            if ($previous && (int) $previous !== (int) $newStaff->id) DeliveryStaffProfile::query()->whereKey($previous)->where('status', 'active')->update(['availability_status' => 'available']);
            $audit->record('delivery.assignment_reassigned', $request->user(), $context->tenant(), $locked, ['reason' => $data['reason'], 'new_staff_id' => $newStaff->uuid]);
            return $locked->fresh($this->shipmentRelations());
        });
        return response()->json(['success' => true, 'message' => 'Delivery reassigned.', 'data' => $this->shipmentData($updated)]);
    }

    public function status(Request $request, MarketplaceShipment $shipment, TenantContext $context, MarketplaceFulfillmentService $fulfillment, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($shipment, $context);
        $data = $request->validate(['status' => ['required', 'in:accepted,picked_up,in_transit,delivered,failed,returned,cancelled'], 'description' => ['nullable', 'string', 'max:2000'], 'proof_of_delivery_note' => ['nullable', 'string', 'max:2000'], 'proof_of_delivery' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']]);
        $mapped = ['picked_up' => 'shipped', 'in_transit' => 'in_transit', 'delivered' => 'delivered', 'failed' => 'delivery_failed', 'returned' => 'returned', 'cancelled' => 'cancelled'];
        if ($data['status'] === 'accepted') {
            $updated = DB::transaction(function () use ($request, $context, $shipment, $data, $audit): MarketplaceShipment {
                $locked = MarketplaceShipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
                if ($locked->assignment_status !== 'assigned') throw new ConflictHttpException('Only an assigned delivery can be accepted.');
                $locked->update(['assignment_status' => 'accepted']);
                $this->recordAssignmentEvent($locked, 'accepted', $request->user()->id, $data['description'] ?? null);
                $audit->record('delivery.status_accepted', $request->user(), $context->tenant(), $locked);
                return $locked->fresh($this->shipmentRelations());
            });
        } else {
            $updated = $fulfillment->transitionShipment($shipment, $request->user(), $mapped[$data['status']], $data['description'] ?? null);
            DB::transaction(function () use ($request, $context, $updated, $data, $audit): void {
                $locked = MarketplaceShipment::query()->whereKey($updated->id)->lockForUpdate()->firstOrFail();
                $locked->assignment_status = $data['status'];
                if ($data['status'] === 'delivered' && $request->hasFile('proof_of_delivery')) $locked->proof_of_delivery_path = $request->file('proof_of_delivery')->store('delivery/'.$locked->tenant_id.'/proof', 'private');
                if ($data['proof_of_delivery_note'] ?? null) $locked->proof_of_delivery_note = $data['proof_of_delivery_note'];
                $locked->save();
                $this->recordAssignmentEvent($locked, $data['status'], $request->user()->id, $data['description'] ?? null);
                if ($locked->delivery_staff_profile_id && in_array($data['status'], ['delivered', 'failed', 'returned', 'cancelled'], true)) DeliveryStaffProfile::query()->whereKey($locked->delivery_staff_profile_id)->where('status', 'active')->update(['availability_status' => 'available']);
                $audit->record('delivery.status_'.$data['status'], $request->user(), $context->tenant(), $locked, ['status' => $data['status']]);
            });
            $updated = MarketplaceShipment::query()->whereKey($updated->id)->with($this->shipmentRelations())->firstOrFail();
        }
        return response()->json(['success' => true, 'message' => 'Delivery status updated.', 'data' => $this->shipmentData($updated)]);
    }

    public function proof(MarketplaceShipment $shipment, TenantContext $context)
    {
        $this->assertTenant($shipment, $context);
        abort_if(! $shipment->proof_of_delivery_path || ! Storage::disk('private')->exists($shipment->proof_of_delivery_path), 404);
        return Storage::disk('private')->download($shipment->proof_of_delivery_path);
    }

    public function reports(Request $request, TenantContext $context): JsonResponse
    {
        $query = MarketplaceShipment::query()->where('tenant_id', $context->tenant()->id);
        if ($request->filled('from')) $query->whereDate('created_at', '>=', $request->date('from'));
        if ($request->filled('to')) $query->whereDate('created_at', '<=', $request->date('to'));
        $staff = DeliveryStaffProfile::query()->where('tenant_id', $context->tenant()->id)->with('employee.user')->get()->map(function (DeliveryStaffProfile $profile) use ($query): array {
            $shipments = (clone $query)->where('delivery_staff_profile_id', $profile->id);
            return ['id' => $profile->uuid, 'name' => $profile->employee?->user?->name ?: $profile->staff_code, 'delivered' => (clone $shipments)->where('status', 'delivered')->count(), 'failed' => (clone $shipments)->where('status', 'delivery_failed')->count(), 'total' => $shipments->count()];
        })->values();
        return response()->json(['success' => true, 'data' => ['summary' => ['total' => (clone $query)->count(), 'delivered' => (clone $query)->where('status', 'delivered')->count(), 'failed' => (clone $query)->where('status', 'delivery_failed')->count(), 'active' => (clone $query)->whereNotIn('status', self::TERMINAL_SHIPMENT_STATUSES)->count()], 'staff' => $staff]]);
    }

    public function courierPartners(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        if ($request->isMethod('get')) return response()->json(['success' => true, 'data' => DeliveryCourierPartner::query()->where('tenant_id', $context->tenant()->id)->orderBy('name')->get()->map(fn (DeliveryCourierPartner $partner): array => ['id' => $partner->uuid, 'name' => $partner->name, 'phone' => $partner->phone, 'email' => $partner->email, 'status' => $partner->status, 'notes' => $partner->notes])]);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000']]);
        if (DeliveryCourierPartner::query()->where('tenant_id', $context->tenant()->id)->where('name', $data['name'])->exists()) throw new ConflictHttpException('This courier partner already exists.');
        $partner = DeliveryCourierPartner::create([...$data, 'tenant_id' => $context->tenant()->id, 'created_by' => $request->user()->id]);
        $audit->record('delivery.courier_partner_created', $request->user(), $context->tenant(), $partner);
        return response()->json(['success' => true, 'data' => ['id' => $partner->uuid, 'name' => $partner->name, 'status' => $partner->status]], 201);
    }

    public function courierPartnerUpdate(Request $request, DeliveryCourierPartner $partner, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($partner, $context);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'], 'status' => ['nullable', 'in:active,inactive']]);
        if (DeliveryCourierPartner::query()->where('tenant_id', $context->tenant()->id)->where('name', $data['name'])->where('id', '!=', $partner->id)->exists()) throw new ConflictHttpException('This courier partner already exists.');
        $partner->update($data);
        $audit->record('delivery.courier_partner_updated', $request->user(), $context->tenant(), $partner, ['changed_fields' => array_keys($data)]);
        return response()->json(['success' => true, 'data' => ['id' => $partner->uuid, 'name' => $partner->name, 'status' => $partner->status]]);
    }

    public function courierPartnerDeactivate(Request $request, DeliveryCourierPartner $partner, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $this->assertTenant($partner, $context);
        $partner->update(['status' => 'inactive']);
        $audit->record('delivery.courier_partner_deactivated', $request->user(), $context->tenant(), $partner);
        return response()->json(['success' => true, 'message' => 'Courier partner deactivated.']);
    }

    private function shipmentList(Request $request, TenantContext $context, bool $history): JsonResponse
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string'], 'per_page' => ['nullable', 'integer', 'in:10,20,50,100']]);
        $query = MarketplaceShipment::query()->where('tenant_id', $context->tenant()->id)->with($this->shipmentRelations());
        $history ? $query->whereIn('status', self::TERMINAL_SHIPMENT_STATUSES) : $query->whereNotIn('status', self::TERMINAL_SHIPMENT_STATUSES);
        if (! empty($data['status'])) $query->where('status', $data['status']);
        if (! empty($data['search'])) {
            $term = '%'.trim($data['search']).'%';
            $query->where(function (Builder $builder) use ($term): void { $builder->where('internal_tracking_id', 'ilike', $term)->orWhere('tracking_number', 'ilike', $term)->orWhereHas('vendorOrder', fn (Builder $order) => $order->where('vendor_order_number', 'ilike', $term)); });
        }
        $paginator = $query->latest('created_at')->paginate($data['per_page'] ?? 20);
        $paginator->through(fn (MarketplaceShipment $shipment): array => $this->shipmentData($shipment));
        return response()->json(['success' => true, 'data' => $paginator]);
    }

    private function staffRules(int $tenantId, bool $creating = true): array
    {
        return [
            'employee_profile_id' => [$creating ? 'required' : 'sometimes', 'string'],
            'store_id' => ['nullable', 'string'], 'staff_code' => ['nullable', 'string', 'max:50'], 'vehicle_type' => ['nullable', 'string', 'max:50'],
            'vehicle_plate' => ['nullable', 'string', 'max:50'], 'availability_status' => ['nullable', 'in:available,busy,off_duty'],
        ];
    }

    private function staffData(DeliveryStaffProfile $staff): array
    {
        return ['id' => $staff->uuid, 'staff_code' => $staff->staff_code, 'status' => $staff->status, 'availability_status' => $staff->availability_status, 'vehicle_type' => $staff->vehicle_type, 'vehicle_plate' => $staff->vehicle_plate, 'store' => $staff->store ? ['id' => $staff->store->uuid, 'name' => $staff->store->name] : null, 'employee' => ['id' => $staff->employee?->uuid, 'name' => $staff->employee?->user?->name ?: $staff->staff_code, 'email' => $staff->employee?->user?->email, 'phone' => $staff->employee?->phone, 'emergency_contact' => $staff->employee?->emergency_contact_phone]];
    }

    private function orderData(MarketplaceVendorOrder $order): array
    {
        return ['id' => $order->uuid, 'vendor_order_number' => $order->vendor_order_number, 'order_number' => $order->order?->order_number, 'customer_name' => $order->order?->customer?->name ?: $order->order?->customer?->email, 'delivery_address' => $order->order?->delivery_address, 'total' => $order->total, 'currency_code' => $order->currency_code, 'status' => $order->status, 'created_at' => $order->created_at?->toIso8601String(), 'store' => $order->store ? ['id' => $order->store->uuid, 'name' => $order->store->name] : null];
    }

    private function shipmentData(MarketplaceShipment $shipment): array
    {
        return ['id' => $shipment->uuid, 'status' => $shipment->status, 'assignment_status' => $shipment->assignment_status, 'delivery_type' => $shipment->delivery_type, 'delivery_date' => $shipment->delivery_date?->toDateString(), 'assigned_at' => $shipment->assigned_at?->toIso8601String(), 'internal_tracking_id' => $shipment->internal_tracking_id, 'tracking_number' => $shipment->tracking_number, 'carrier_name' => $shipment->carrier_name, 'delivery_notes' => $shipment->delivery_notes, 'proof_of_delivery_note' => $shipment->proof_of_delivery_note, 'created_at' => $shipment->created_at?->toIso8601String(), 'shipped_at' => $shipment->shipped_at?->toIso8601String(), 'delivered_at' => $shipment->delivered_at?->toIso8601String(), 'order' => $shipment->vendorOrder ? $this->orderData($shipment->vendorOrder) : null, 'staff' => $shipment->deliveryStaff ? ['id' => $shipment->deliveryStaff->uuid, 'name' => $shipment->deliveryStaff->employee?->user?->name ?: $shipment->deliveryStaff->staff_code, 'staff_code' => $shipment->deliveryStaff->staff_code] : null, 'courier_partner' => $shipment->courierPartner ? ['id' => $shipment->courierPartner->uuid, 'name' => $shipment->courierPartner->name] : null, 'events' => $shipment->assignmentEvents?->map(fn (DeliveryAssignmentEvent $event): array => ['id' => $event->id, 'type' => $event->event_type, 'reason' => $event->reason, 'notes' => $event->notes, 'event_time' => $event->event_time?->toIso8601String(), 'actor_name' => $event->actor?->name])->values() ?? []];
    }

    private function shipmentRelations(): array
    {
        return ['vendorOrder.order.customer', 'vendorOrder.store', 'deliveryStaff.employee.user', 'courierPartner', 'assignmentEvents.actor'];
    }

    private function recordAssignmentEvent(MarketplaceShipment $shipment, string $type, int $actorId, ?string $description): void
    {
        DeliveryAssignmentEvent::create(['tenant_id' => $shipment->tenant_id, 'marketplace_shipment_id' => $shipment->id, 'delivery_staff_profile_id' => $shipment->delivery_staff_profile_id, 'event_type' => $type, 'notes' => $description, 'actor_user_id' => $actorId, 'event_time' => now()]);
    }

    private function assertTenant(object $model, TenantContext $context): void
    {
        if ((int) $model->tenant_id !== (int) $context->tenant()->id) abort(404);
    }

    private function storeId(?string $uuid, int $tenantId): ?int
    {
        if (! $uuid) return null;
        return DB::table('stores')->where('tenant_id', $tenantId)->where('uuid', $uuid)->where('status', 'active')->value('id') ?: throw new UnprocessableEntityHttpException('Select an active branch in this business.');
    }

    private function nextStaffCode(int $tenantId): string
    {
        $number = (int) DeliveryStaffProfile::query()->where('tenant_id', $tenantId)->lockForUpdate()->get(['id'])->count() + 1;
        do { $code = 'DLV-'.str_pad((string) $number++, 4, '0', STR_PAD_LEFT); } while (DeliveryStaffProfile::query()->where('tenant_id', $tenantId)->where('staff_code', $code)->exists());
        return $code;
    }
}
