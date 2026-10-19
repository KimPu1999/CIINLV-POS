<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\CreateBusinessRequest;
use App\Http\Requests\Platform\UpdateBusinessRequest;
use App\Http\Resources\TenantResource;
use App\Http\Resources\BusinessInvitationResource;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\BusinessProvisioningService;
use App\Services\BusinessSuspensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PlatformBusinessController
{
    public function index(Request $request): JsonResponse
    {
        $businesses = Tenant::query()
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';
                $query->where('name', 'ilike', $search)->orWhere('business_code', 'ilike', $search);
            }))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->latest()
            ->paginate(min((int) $request->input('per_page', 25), 100));

        return response()->json(['success' => true, 'data' => TenantResource::collection($businesses)]);
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
            (new TenantResource($business->load('businessSetting')))->resolve(),
            [
                'members_count' => $business->memberships()->count(),
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
        $business = $suspensionService->suspend($business, $request->user(), app(AuditLogger::class));

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
}
