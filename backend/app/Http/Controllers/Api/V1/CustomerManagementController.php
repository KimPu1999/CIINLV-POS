<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\CustomerStatusRequest;
use App\Models\AuditLog;
use App\Models\MarketplaceOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CustomerManagementController
{
    public function platformIndex(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,suspended'],
            'verification' => ['nullable', 'in:verified,unverified'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'last_login_from' => ['nullable', 'date'],
            'last_login_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $customers = $this->customerQuery()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(fn (Builder $search) => $search
                    ->where('users.name', 'ilike', $term)
                    ->orWhere('users.email', 'ilike', $term)
                    ->orWhereHas('customerProfile', fn (Builder $profile) => $profile->where('phone', 'ilike', $term))
                    ->when(is_numeric(trim((string) $request->input('search'))), fn (Builder $search) => $search->orWhere('users.id', (int) $request->input('search'))));
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->whereHas('customerProfile', fn (Builder $profile) => $profile->where('status', (string) $request->input('status'))))
            ->when($request->input('verification') === 'verified', fn (Builder $query) => $query->whereNotNull('users.email_verified_at'))
            ->when($request->input('verification') === 'unverified', fn (Builder $query) => $query->whereNull('users.email_verified_at'))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('users.created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('users.created_at', '<=', $request->date('to')))
            ->when($request->filled('last_login_from'), fn (Builder $query) => $query->whereDate('users.last_login_at', '>=', $request->date('last_login_from')))
            ->when($request->filled('last_login_to'), fn (Builder $query) => $query->whereDate('users.last_login_at', '<=', $request->date('last_login_to')))
            ->withCount('marketplaceOrders')
            ->latest('users.created_at')
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $customers->getCollection()->map(fn (User $customer) => $this->summary($customer))->values(), 'meta' => [...$this->meta($customers), 'statistics' => $this->statistics()]]);
    }

    public function businessIndex(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);

        $tenant = $tenantContext->tenant();
        $customers = $this->customerQuery()
            ->whereHas('marketplaceOrders.vendorOrders', fn (Builder $query) => $query->where('tenant_id', $tenant->id))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(fn (Builder $search) => $search
                    ->where('users.name', 'ilike', $term)
                    ->orWhere('users.email', 'ilike', $term));
            })
            ->withCount(['marketplaceOrders as business_order_count' => fn (Builder $query) => $query->whereHas('vendorOrders', fn (Builder $vendor) => $vendor->where('tenant_id', $tenant->id))])
            ->latest('users.created_at')
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $customers->getCollection()->map(fn (User $customer) => $this->summary($customer, true))->values(), 'meta' => [...$this->meta($customers), 'statistics' => $this->statistics($tenant)]]);
    }

    public function platformShow(User $customer): JsonResponse
    {
        $customer = $this->platformCustomer($customer)->load(['customerProfile.addresses']);

        return response()->json(['success' => true, 'data' => $this->detail($customer)]);
    }

    public function businessShow(User $customer, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->tenant();
        $customer = $this->businessCustomer($customer, $tenant)->load(['customerProfile.addresses']);

        return response()->json(['success' => true, 'data' => $this->detail($customer, $tenant)]);
    }

    public function platformOrders(User $customer, Request $request): JsonResponse
    {
        $customer = $this->platformCustomer($customer);
        $orders = $customer->marketplaceOrders()->with(['vendorOrders.store'])->latest()->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $orders->getCollection()->map(fn (MarketplaceOrder $order) => $this->orderSummary($order))->values(), 'meta' => $this->meta($orders)]);
    }

    public function businessOrders(User $customer, Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->tenant();
        $customer = $this->businessCustomer($customer, $tenant);
        $orders = $customer->marketplaceOrders()
            ->whereHas('vendorOrders', fn (Builder $query) => $query->where('tenant_id', $tenant->id))
            ->with(['vendorOrders' => fn ($query) => $query->where('tenant_id', $tenant->id)->with('store')])
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $orders->getCollection()->map(fn (MarketplaceOrder $order) => $this->orderSummary($order))->values(), 'meta' => $this->meta($orders)]);
    }

    public function activity(User $customer, Request $request): JsonResponse
    {
        $customer = $this->platformCustomer($customer);
        $logs = AuditLog::query()->with('actor:id,name,email')
            ->where('subject_type', User::class)
            ->where('subject_id', (string) $customer->id)
            ->latest('created_at')
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $logs->getCollection()->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name, 'email' => $log->actor->email] : null,
            'metadata' => $log->metadata,
            'created_at' => $log->created_at?->toIso8601String(),
        ])->values(), 'meta' => $this->meta($logs)]);
    }

    public function updateStatus(CustomerStatusRequest $request, User $customer, AuditLogger $auditLogger): JsonResponse
    {
        $customer = $this->platformCustomer($customer);
        $status = $request->validated('status');
        $previous = $customer->customerProfile->status;

        DB::transaction(function () use ($customer, $status): void {
            $customer->customerProfile()->update(['status' => $status]);
            $customer->forceFill(['status' => $status])->save();
            if ($status === User::STATUS_SUSPENDED) {
                $customer->tokens()->delete();
            }
        });

        $auditLogger->record('platform.customer_status_updated', $request->user(), subject: $customer, metadata: [
            'from_status' => $previous,
            'to_status' => $status,
        ]);

        return response()->json(['success' => true, 'data' => $this->summary($customer->refresh()->load('customerProfile'))]);
    }

    public function sendPasswordReset(Request $request, User $customer, AuditLogger $auditLogger): JsonResponse
    {
        $customer = $this->platformCustomer($customer);
        Password::sendResetLink(['email' => $customer->email]);
        $auditLogger->record('platform.customer_password_reset_requested', $request->user(), subject: $customer);

        return response()->json(['success' => true, 'message' => 'If this customer account is active, password reset instructions have been sent.']);
    }

    private function customerQuery(): Builder
    {
        return User::query()
            ->whereNull('users.platform_role')
            ->whereHas('customerProfile')
            ->with('customerProfile');
    }

    private function statistics(?Tenant $tenant = null): array
    {
        $base = $this->customerQuery();
        if ($tenant) {
            $base->whereHas('marketplaceOrders.vendorOrders', fn (Builder $query) => $query->where('tenant_id', $tenant->id));
        }

        $monthStart = now()->startOfMonth();

        return [
            'total' => (clone $base)->count('users.id'),
            'active' => (clone $base)->whereHas('customerProfile', fn (Builder $query) => $query->where('status', 'active'))->count('users.id'),
            'suspended' => (clone $base)->whereHas('customerProfile', fn (Builder $query) => $query->where('status', 'suspended'))->count('users.id'),
            'new_this_month' => (clone $base)->where('users.created_at', '>=', $monthStart)->count('users.id'),
        ];
    }

    private function platformCustomer(User $customer): User
    {
        if (! $customer->customerProfile || $customer->platform_role !== null) {
            throw new AccessDeniedHttpException('The selected account is not a marketplace customer.');
        }

        return $customer;
    }

    private function businessCustomer(User $customer, Tenant $tenant): User
    {
        $customer = $this->platformCustomer($customer);
        if (! $customer->marketplaceOrders()->whereHas('vendorOrders', fn (Builder $query) => $query->where('tenant_id', $tenant->id))->exists()) {
            throw new AccessDeniedHttpException('This customer is not associated with the selected business.');
        }

        return $customer;
    }

    private function summary(User $customer, bool $business = false): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->customerProfile?->phone,
            'status' => $customer->customerProfile?->status,
            'registered_at' => $customer->created_at?->toIso8601String(),
            'last_login_at' => $customer->last_login_at?->toIso8601String(),
            'email_verified_at' => $customer->email_verified_at?->toIso8601String(),
            'order_count' => (int) ($customer->business_order_count ?? $customer->marketplace_orders_count ?? 0),
            'business_scoped' => $business,
        ];
    }

    private function detail(User $customer, ?Tenant $tenant = null): array
    {
        $data = $this->summary($customer);
        $data['terms_accepted_at'] = $customer->customerProfile?->terms_accepted_at?->toIso8601String();
        $data['terms_version'] = $customer->customerProfile?->terms_version;
        $data['addresses'] = $customer->customerProfile?->addresses?->map(fn ($address) => [
            'id' => $address->id,
            'recipient_name' => $address->recipient_name,
            'phone' => $address->phone,
            'address_line_1' => $address->address_line_1,
            'address_line_2' => $address->address_line_2,
            'city' => $address->city,
            'region' => $address->region,
            'postal_code' => $address->postal_code,
            'country' => $address->country,
            'is_default' => $address->is_default,
        ])->values() ?? [];
        if ($tenant) {
            $data['business_order_count'] = $customer->marketplaceOrders()->whereHas('vendorOrders', fn (Builder $query) => $query->where('tenant_id', $tenant->id))->count();
        }

        return $data;
    }

    private function orderSummary(MarketplaceOrder $order): array
    {
        return [
            'id' => $order->uuid,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'grand_total' => $order->grand_total,
            'currency_code' => $order->currency_code,
            'created_at' => $order->created_at?->toIso8601String(),
            'businesses' => $order->vendorOrders->map(fn ($vendor) => ['id' => $vendor->tenant_id, 'name' => $vendor->store?->name, 'status' => $vendor->status, 'total' => $vendor->total])->values(),
        ];
    }

    private function perPage(Request $request): int
    {
        $requested = (int) $request->input('per_page', 25);

        return in_array($requested, [25, 50, 100], true) ? $requested : 25;
    }

    private function meta($paginator): array
    {
        return ['total' => $paginator->total(), 'per_page' => $paginator->perPage(), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage()];
    }
}
