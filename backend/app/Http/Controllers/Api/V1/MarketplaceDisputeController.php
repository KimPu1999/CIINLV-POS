<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\MarketplaceDisputeRequest;
use App\Http\Resources\MarketplaceDisputeResource;
use App\Models\MarketplaceDispute;
use App\Models\MarketplaceDisputeEvidence;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceDisputeService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceDisputeController
{
    public function customerIndex(Request $request): JsonResponse
    {
        $rows = MarketplaceDispute::query()->where('customer_user_id', $request->user()->id)->with(['order', 'vendorOrder.store', 'tenant'])->latest()->paginate(min(max((int) $request->input('per_page', 20), 1), 50));
        return response()->json(['success' => true, 'data' => MarketplaceDisputeResource::collection($rows), 'meta' => $this->meta($rows)]);
    }

    public function customerStore(MarketplaceDisputeRequest $request, string $order, MarketplaceDisputeService $service, MarketplaceAuditLogger $audit): JsonResponse
    {
        $model = MarketplaceOrder::query()->where('customer_user_id', $request->user()->id)->where('uuid', $order)->firstOrFail();
        return response()->json(['success' => true, 'data' => new MarketplaceDisputeResource($service->create($model, $request->user(), $request->validated(), $audit))], 201);
    }

    public function customerShow(Request $request, MarketplaceDispute $dispute): JsonResponse
    {
        abort_unless($dispute->customer_user_id === $request->user()->id, 404);
        return $this->show($dispute, false);
    }

    public function vendorIndex(TenantContext $context): JsonResponse
    {
        $rows = MarketplaceDispute::query()->where('tenant_id', $context->tenant()->id)->with(['order', 'vendorOrder.store', 'customer'])->latest()->paginate(25);
        return response()->json(['success' => true, 'data' => MarketplaceDisputeResource::collection($rows), 'meta' => $this->meta($rows)]);
    }

    public function vendorShow(MarketplaceDispute $dispute, TenantContext $context): JsonResponse
    {
        abort_unless($dispute->tenant_id === $context->tenant()->id, 404);
        return $this->show($dispute, false);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = MarketplaceDispute::query()->with(['order', 'vendorOrder.store', 'tenant', 'customer', 'reviewer'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($search) => $search->where('reference', 'ilike', '%'.$request->input('search').'%')->orWhereHas('order', fn ($order) => $order->where('order_number', 'ilike', '%'.$request->input('search').'%'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            ->latest();
        $rows = $query->paginate(min(max((int) $request->input('per_page', 25), 1), 100));
        return response()->json(['success' => true, 'data' => MarketplaceDisputeResource::collection($rows), 'meta' => $this->meta($rows)]);
    }

    public function adminDashboard(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['total' => MarketplaceDispute::count(), ...collect(MarketplaceDispute::STATUSES)->mapWithKeys(fn ($status) => [$status => MarketplaceDispute::where('status', $status)->count()])->all()]]);
    }

    public function adminShow(MarketplaceDispute $dispute): JsonResponse { return $this->show($dispute, true); }

    public function assign(Request $request, MarketplaceDispute $dispute, MarketplaceAuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reviewer_id' => ['nullable', 'integer', 'exists:users,id']]);
        $reviewer = $data['reviewer_id'] ? User::query()->whereKey($data['reviewer_id'])->where('platform_role', User::PLATFORM_ROLE_SUPER_ADMIN)->firstOrFail() : null;
        $dispute->update(['assigned_reviewer_id' => $reviewer?->id]);
        $audit->record('marketplace.dispute_reviewer_assigned', $request->user(), $dispute->tenant, $dispute, ['reviewer_id' => $reviewer?->id]);
        return response()->json(['success' => true, 'data' => new MarketplaceDisputeResource($dispute->fresh()->load('reviewer'))]);
    }

    public function message(Request $request, MarketplaceDispute $dispute, MarketplaceAuditLogger $audit, MarketplaceDisputeService $service): JsonResponse
    {
        $this->assertParticipant($request, $dispute);
        $data = $request->validate(['message' => ['required', 'string', 'min:1', 'max:10000'], 'internal' => ['sometimes', 'boolean']]);
        $internal = $request->user()->isSuperAdmin() && $request->boolean('internal');
        return response()->json(['success' => true, 'data' => $service->addMessage($dispute, $request->user(), $data['message'], $internal, $audit)]);
    }

    public function status(Request $request, MarketplaceDispute $dispute, MarketplaceDisputeService $service, MarketplaceAuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $this->tenantMember($request, $dispute), 403);
        $data = $request->validate(['status' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:2000']]);
        if (! $request->user()->isSuperAdmin() && in_array($data['status'], ['resolved', 'rejected', 'closed'], true)) abort(403, 'Only marketplace reviewers can finalize disputes.');
        return response()->json(['success' => true, 'data' => new MarketplaceDisputeResource($service->changeStatus($dispute, $data['status'], $request->user(), $data['reason'] ?? null, $audit))]);
    }

    public function resolve(Request $request, MarketplaceDispute $dispute, MarketplaceDisputeService $service, MarketplaceAuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string'], 'amount' => ['nullable', 'numeric', 'gt:0'], 'note' => ['required', 'string', 'min:3', 'max:5000']]);
        return response()->json(['success' => true, 'data' => new MarketplaceDisputeResource($service->resolve($dispute, $request->user(), $data, $audit))]);
    }

    public function evidence(Request $request, MarketplaceDispute $dispute, MarketplaceDisputeService $service, MarketplaceAuditLogger $audit): JsonResponse
    {
        $this->assertParticipant($request, $dispute);
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.config('marketplace.disputes.max_evidence_size_kb', 10240)]]);
        return response()->json(['success' => true, 'data' => $service->addEvidence($dispute, $request->user(), $request->file('file'), $audit)], 201);
    }

    public function download(Request $request, MarketplaceDisputeEvidence $evidence): StreamedResponse
    {
        $dispute = $evidence->dispute()->with('tenant')->firstOrFail();
        $user = $request->user();
        $allowed = $user->isSuperAdmin() || $dispute->customer_user_id === $user->id || ($dispute->tenant_id && $user->tenants()->whereKey($dispute->tenant_id)->wherePivot('status', 'active')->exists());
        abort_unless($allowed, 403);
        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404);
        return Storage::disk($evidence->disk)->download($evidence->path, $evidence->original_name, ['Content-Type' => $evidence->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    private function show(MarketplaceDispute $dispute, bool $includeInternal): JsonResponse
    {
        $dispute->load(['order', 'vendorOrder.store', 'item', 'tenant', 'customer', 'reviewer', 'evidence.uploader', 'resolution.approver', 'history.actor', 'messages.sender']);
        if (! $includeInternal) $dispute->setRelation('messages', $dispute->messages->where('visibility', 'participants')->values());
        return response()->json(['success' => true, 'data' => new MarketplaceDisputeResource($dispute)]);
    }

    private function meta($page): array { return ['total' => $page->total(), 'per_page' => $page->perPage(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]; }

    private function tenantMember(Request $request, MarketplaceDispute $dispute): bool
    {
        return $dispute->tenant_id !== null && $request->user()->tenants()->whereKey($dispute->tenant_id)->wherePivot('status', 'active')->exists();
    }

    private function assertParticipant(Request $request, MarketplaceDispute $dispute): void
    {
        abort_unless($request->user()->isSuperAdmin() || $dispute->customer_user_id === $request->user()->id || $this->tenantMember($request, $dispute), 403);
        if (! $request->user()->isSuperAdmin() && $request->boolean('internal')) abort(403);
    }
}
