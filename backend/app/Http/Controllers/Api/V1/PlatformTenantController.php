<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Platform\CreateTenantRequest;
use App\Http\Requests\Platform\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PlatformTenantController
{
    public function index(): AnonymousResourceCollection
    {
        return TenantResource::collection(Tenant::query()->latest()->paginate(25));
    }

    public function store(CreateTenantRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $tenant = DB::transaction(function () use ($request): Tenant {
            $data = $request->validated();
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']).'-'.Str::lower(Str::random(6)),
                'status' => 'active',
                'subscription_status' => $data['subscription_status'] ?? 'trial',
            ]);

            foreach (config('roles.tenant') as $role) {
                $tenant->roles()->create(['name' => $role]);
            }

            return $tenant;
        });

        $auditLogger->record('platform.tenant_created', $request->user(), subject: $tenant);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant)], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json([
            'success' => true,
            'data' => array_merge((new TenantResource($tenant))->resolve(), [
                'members_count' => $tenant->memberships()->count(),
                'stores_count' => $tenant->stores()->count(),
            ]),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant, AuditLogger $auditLogger): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        $tenant->update($request->validated());
        $auditLogger->record('platform.tenant_updated', $request->user(), subject: $tenant);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }

    public function suspend(Tenant $tenant, AuditLogger $auditLogger, Request $request): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        abort_if($tenant->status === 'archived', 409, 'Restore the archived business before suspending it.');
        $tenant->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
        $auditLogger->record('platform.tenant_suspended', $request->user(), subject: $tenant);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }

    public function activate(Tenant $tenant, AuditLogger $auditLogger, Request $request): JsonResponse
    {
        Gate::authorize('manage', $tenant);
        abort_if($tenant->status === 'archived', 409, 'Restore the archived business before activating it.');
        $tenant->forceFill(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null])->save();
        $auditLogger->record('platform.tenant_activated', $request->user(), subject: $tenant);

        return response()->json(['success' => true, 'data' => new TenantResource($tenant->refresh())]);
    }
}
