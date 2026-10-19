<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\CreateBusinessRequest;
use App\Http\Requests\Platform\UpdateBusinessRequest;
use App\Http\Resources\BusinessInvitationResource;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\BusinessProvisioningService;
use App\Services\BusinessSuspensionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlatformBusinessController
{
    public function index(Request $request): JsonResponse
    {
        $businesses = $this->businessesQuery($request)->paginate($this->perPage($request));

        return response()->json(['success' => true,
            'data' => TenantResource::collection($businesses->getCollection())->resolve(),
            'meta' => [
                'current_page' => $businesses->currentPage(),
                'last_page' => $businesses->lastPage(),
                'per_page' => $businesses->perPage(),
                'total' => $businesses->total(),
            ],
        ]);
    }

    public function statistics(): JsonResponse
    {
        $pendingRegistrations = Tenant::query()->where(fn (Builder $query) => $query->where('approval_status', 'pending')->orWhere('status', 'pending'))->count();
        $pendingOwnerActivations = Tenant::query()->whereHas('memberships', fn (Builder $query) => $query->where('status', 'pending')->whereHas('role', fn (Builder $role) => $role->where('name', 'business_admin')))->count();

        return response()->json(['success' => true, 'data' => [
            'total_businesses' => Tenant::query()->count(),
            'active_businesses' => Tenant::query()->where('status', 'active')->count(),
            'trial_businesses' => Tenant::query()->where('subscription_status', 'trial')->count(),
            'suspended_businesses' => Tenant::query()->where('status', 'suspended')->count(),
            'pending_registrations' => $pendingRegistrations,
            'pending_owner_activations' => $pendingOwnerActivations,
            'expired_subscriptions' => null,
            'new_businesses_this_month' => Tenant::query()->whereBetween('created_at', [now()->startOfMonth(), now()])->count(),
            'subscription_data_available' => false,
            'subscription_data_message' => 'Subscription plans, renewal dates, and verified platform billing records are not stored in the current schema.',
        ]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $businesses = $this->businessesQuery($request)->orderBy('tenants.id')->lazyById(100, 'tenants.id', 'id');

        return response()->streamDownload(function () use ($businesses): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Business Name', 'Business Code', 'Owner', 'Owner Email', 'Business Status', 'Owner Activation', 'Subscription Status', 'Branches', 'Created Date']);
            foreach ($businesses as $business) {
                $owner = $business->memberships->sortByDesc(fn ($membership): int => $membership->status === 'active' ? 1 : 0)->first()?->user;
                fputcsv($handle, [
                    $this->csvValue($business->name), $this->csvValue($business->business_code),
                    $this->csvValue($owner?->name), $this->csvValue($owner?->email),
                    $this->csvValue($business->status), $this->csvValue($this->ownerActivationStatus($business, $owner)),
                    $this->csvValue($business->subscription_status), $business->stores_count,
                    $this->csvValue($business->created_at?->toIso8601String()),
                ]);
            }
            fclose($handle);
        }, 'businesses-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(CreateBusinessRequest $request, BusinessProvisioningService $provisioningService): JsonResponse
    {
        $result = $provisioningService->provision($request->user(), $request->validated(), $request->header('Idempotency-Key'));

        return response()->json([
            'success' => true,
            'message' => 'Business created successfully',
            'data' => $this->creationPayload($result),
        ], 201);
    }

    public function show(Tenant $business): JsonResponse
    {
        Gate::authorize('manage', $business);

        return response()->json(['success' => true, 'data' => array_merge(
            (new TenantResource($business->load(['businessSetting', 'memberships' => fn ($query) => $query->whereHas('role', fn ($role) => $role->where('name', 'business_admin'))->with(['user:id,name,email,email_verified_at', 'role:id,name'])])))->resolve(),
            [
                'members_count' => $business->memberships()->count(),
                'active_members_count' => $business->memberships()->where('status', 'active')->count(),
                'stores_count' => $business->stores()->count(),
                'settings' => $business->businessSetting,
            ],
        )]);
    }

    public function update(UpdateBusinessRequest $request, Tenant $business, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('manage', $business);
        $business->update($request->validated());
        $auditLogger->record('platform.business_updated', $request->user(), subject: $business);

        return response()->json(['success' => true, 'data' => new TenantResource($business->refresh())]);
    }

    public function suspend(Tenant $business, Request $request, BusinessSuspensionService $suspensionService): JsonResponse
    {
        Gate::authorize('manage', $business);
        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $business = $suspensionService->suspend($business, $request->user(), app(AuditLogger::class), $request->string('reason')->trim()->toString() ?: 'Platform suspension');

        return response()->json(['success' => true, 'data' => new TenantResource($business)]);
    }

    public function activate(Tenant $business, Request $request, BusinessSuspensionService $suspensionService): JsonResponse
    {
        Gate::authorize('manage', $business);
        $business = $suspensionService->activate($business, $request->user(), app(AuditLogger::class));

        return response()->json(['success' => true, 'data' => new TenantResource($business)]);
    }

    private function creationPayload(array $result): array
    {
        $invitation = $result['invitation']?->refresh()?->load(['tenant', 'user']);
        $payload = [
            'business' => new TenantResource($result['tenant']),
            'administrator' => [
                'name' => $result['admin']->name,
                'email' => $result['admin']->email,
                'role' => $result['membership']->role?->name,
                'invitation_status' => $invitation?->status() ?? $result['invitation_status'],
            ],
            'invitation' => $invitation ? (new BusinessInvitationResource($invitation))->resolve() : null,
        ];

        return $payload;
    }

    private function businessesQuery(Request $request): Builder
    {
        $query = Tenant::query()
            ->withCount(['stores', 'memberships'])
            ->with(['memberships' => function ($membership): void {
                $membership->whereHas('role', fn ($role) => $role->where('name', 'business_admin'))
                    ->with(['user:id,name,email,email_verified_at', 'role:id,name']);
            }])
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('tenants.name', 'ilike', $search)
                        ->orWhere('tenants.business_code', 'ilike', $search)
                        ->orWhere('tenants.email', 'ilike', $search)
                        ->orWhereHas('memberships', fn (Builder $membership) => $membership->whereHas('role', fn (Builder $role) => $role->where('name', 'business_admin'))->whereHas('user', fn (Builder $user) => $user->where('name', 'ilike', $search)->orWhere('email', 'ilike', $search)));
                });
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('tenants.status', $request->string('status')->toString()))
            ->when($request->filled('subscription'), fn (Builder $query) => $query->where('tenants.subscription_status', $request->string('subscription')->toString()))
            ->when($request->filled('activation'), function (Builder $query) use ($request): void {
                if ($request->string('activation')->toString() === 'pending') {
                    $query->where(function (Builder $nested): void {
                        $nested->where('email_verification_status', 'pending_email_verification')
                            ->orWhereHas('memberships', fn (Builder $membership) => $membership->where('status', 'pending')->whereHas('role', fn (Builder $role) => $role->where('name', 'business_admin')));
                    });
                } elseif ($request->string('activation')->toString() === 'verified') {
                    $query->where(function (Builder $nested): void {
                        $nested->where('tenants.email_verification_status', 'verified')
                            ->orWhereHas('memberships', fn (Builder $membership) => $membership->whereHas('role', fn (Builder $role) => $role->where('name', 'business_admin'))->whereHas('user', fn (Builder $user) => $user->whereNotNull('email_verified_at')));
                    });
                }
            })
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('tenants.created_at', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('tenants.created_at', '<=', $request->string('to')->toString()));

        $sort = in_array($request->string('sort')->toString(), ['created_at', 'name', 'status', 'subscription_status'], true) ? $request->string('sort')->toString() : 'created_at';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('tenants.'.$sort, $direction)->orderBy('tenants.id', $direction);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 25), 1), 100);
    }

    private function ownerActivationStatus(Tenant $business, mixed $owner): string
    {
        $membership = $business->memberships->sortByDesc(fn ($item): int => $item->status === 'active' ? 1 : 0)->first();

        return $membership?->status === 'pending'
            ? 'pending'
            : ($owner?->email_verified_at ? 'verified' : ($business->email_verification_status ?: 'unknown'));
    }

    private function csvValue(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'".$value : $value;
    }
}
