<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\AssignContactInquiryRequest;
use App\Http\Requests\Marketplace\StoreContactInquiryMessageRequest;
use App\Http\Requests\Marketplace\UpdateContactInquiryStatusRequest;
use App\Jobs\SendMarketplaceContactEmail;
use App\Models\MarketplaceAuditLog;
use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceContactInquiryMessage;
use App\Models\TenantMembership;
use App\Services\MarketplaceAuditLogger;
use App\Services\MarketplaceContactDeliveryService;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceContactInquiryController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:pending,in_progress,resolved,closed'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:10,20,50,100'],
        ]);
        $tenantId = $context->tenant()->id;
        $query = MarketplaceContactInquiry::query()
            ->where('tenant_id', $tenantId)
            ->with(['store:id,name,slug', 'assignee:id,name,email'])
            ->when($validated['status'] ?? null, fn (Builder $builder, string $status): Builder => $builder->where('status', $status))
            ->when($validated['date_from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '>=', $date))
            ->when($validated['date_to'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('created_at', '<=', $date))
            ->when($validated['search'] ?? null, function (Builder $builder, string $term): void {
                $like = '%'.$term.'%';
                $builder->where(fn (Builder $search): Builder => $search
                    ->where('name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('subject', 'ilike', $like)
                    ->orWhere('message', 'ilike', $like));
            })
            ->latest();
        $rows = $query->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn (MarketplaceContactInquiry $inquiry): array => $this->summary($inquiry))->values(),
            'meta' => $this->meta($rows),
            'unread_count' => MarketplaceContactInquiry::query()->where('tenant_id', $tenantId)->whereNull('read_at')->count(),
            'assignees' => $this->assignees($tenantId),
        ]);
    }

    public function show(string $inquiryId, Request $request, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $inquiry = $this->inquiry($inquiryId, $context)
            ->with(['store:id,name,slug', 'assignee:id,name,email', 'messages.sender:id,name,email'])
            ->firstOrFail();
        if (! $inquiry->read_at) {
            $inquiry->forceFill(['read_at' => now(), 'read_by_user_id' => $request->user()->id])->save();
            $audit->record('marketplace.contact_inquiry_read', $request->user(), $context->tenant(), $inquiry);
        }
        $history = MarketplaceAuditLog::query()
            ->where('tenant_id', $context->tenant()->id)
            ->where('entity_type', MarketplaceContactInquiry::class)
            ->where('entity_id', (string) $inquiry->getKey())
            ->with('actor:id,name,email')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (MarketplaceAuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'metadata' => $log->metadata,
                'actor' => $log->actor?->only(['id', 'name', 'email']),
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['success' => true, 'data' => [
            ...$this->summary($inquiry),
            'message' => $inquiry->message,
            'messages' => $inquiry->messages->map(fn (MarketplaceContactInquiryMessage $message): array => $this->message($message))->values(),
            'history' => $history,
            'assignees' => $this->assignees($context->tenant()->id),
        ]]);
    }

    public function status(UpdateContactInquiryStatusRequest $request, string $inquiryId, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $inquiry = $this->inquiry($inquiryId, $context)->firstOrFail();
        $next = $request->validated('status');
        $previous = $inquiry->status;
        if ($previous !== $next) {
            $inquiry->update(['status' => $next]);
            $audit->record('marketplace.contact_inquiry_status_changed', $request->user(), $context->tenant(), $inquiry, ['previous_status' => $previous, 'new_status' => $next]);
        }

        return $this->show($inquiryId, $request, $context, $audit);
    }

    public function assign(AssignContactInquiryRequest $request, string $inquiryId, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $inquiry = $this->inquiry($inquiryId, $context)->firstOrFail();
        $assignedTo = $request->validated('assigned_to_user_id');
        if ($assignedTo !== null && ! TenantMembership::query()
            ->where('tenant_id', $context->tenant()->id)
            ->where('user_id', $assignedTo)
            ->where('status', 'active')
            ->whereHas('user', fn (Builder $user): Builder => $user->where('status', 'active'))
            ->exists()) {
            throw ValidationException::withMessages(['assigned_to_user_id' => ['The selected support agent is not an active member of this business.']]);
        }
        $previous = $inquiry->assigned_to_user_id;
        if ((int) $previous !== (int) $assignedTo) {
            $inquiry->update(['assigned_to_user_id' => $assignedTo]);
            $audit->record('marketplace.contact_inquiry_assigned', $request->user(), $context->tenant(), $inquiry, ['previous_assignee_id' => $previous, 'assignee_id' => $assignedTo]);
        }

        return $this->show($inquiryId, $request, $context, $audit);
    }

    public function note(StoreContactInquiryMessageRequest $request, string $inquiryId, TenantContext $context, MarketplaceAuditLogger $audit): JsonResponse
    {
        $inquiry = $this->inquiry($inquiryId, $context)->firstOrFail();
        $message = $inquiry->messages()->create([
            'sender_user_id' => $request->user()->id,
            'message_type' => MarketplaceContactInquiryMessage::TYPE_INTERNAL_NOTE,
            'body' => trim(strip_tags($request->validated('message'))),
        ]);
        $audit->record('marketplace.contact_inquiry_internal_note_added', $request->user(), $context->tenant(), $inquiry, ['message_id' => $message->id]);

        return $this->show($inquiryId, $request, $context, $audit);
    }

    public function reply(StoreContactInquiryMessageRequest $request, string $inquiryId, TenantContext $context, MarketplaceContactDeliveryService $delivery, MarketplaceAuditLogger $audit): JsonResponse
    {
        $inquiry = $this->inquiry($inquiryId, $context)->with('store')->firstOrFail();
        $recipient = filter_var($inquiry->email, FILTER_VALIDATE_EMAIL) ? $inquiry->email : null;
        $configured = $delivery->mailConfigured() && $recipient;
        $message = DB::transaction(function () use ($request, $inquiry, $configured, $recipient): MarketplaceContactInquiryMessage {
            $message = $inquiry->messages()->create([
                'sender_user_id' => $request->user()->id,
                'message_type' => MarketplaceContactInquiryMessage::TYPE_STAFF_REPLY,
                'body' => trim(strip_tags($request->validated('message'))),
                'delivery_status' => $configured ? MarketplaceContactInquiry::DELIVERY_QUEUED : MarketplaceContactInquiry::DELIVERY_NOT_CONFIGURED,
            ]);
            if ($configured && $recipient) {
                SendMarketplaceContactEmail::dispatch('customer_reply', (int) $message->id, $recipient)->afterCommit();
            }
            if ($inquiry->status === MarketplaceContactInquiry::STATUS_PENDING) {
                $inquiry->update(['status' => MarketplaceContactInquiry::STATUS_IN_PROGRESS]);
            }

            return $message;
        });
        $audit->record('marketplace.contact_inquiry_replied', $request->user(), $context->tenant(), $inquiry, ['message_id' => $message->id, 'delivery_status' => $message->delivery_status]);

        return $this->show($inquiryId, $request, $context, $audit);
    }

    private function inquiry(string $inquiryId, TenantContext $context): Builder
    {
        return MarketplaceContactInquiry::query()->where('tenant_id', $context->tenant()->id)->whereKey($inquiryId);
    }

    /** @return array<string, mixed> */
    private function summary(MarketplaceContactInquiry $inquiry): array
    {
        return [
            'id' => $inquiry->getKey(),
            'customer_name' => $inquiry->name,
            'email' => $inquiry->email,
            'phone' => $inquiry->phone,
            'subject' => $inquiry->subject,
            'message_preview' => mb_substr($inquiry->message, 0, 180),
            'status' => $inquiry->status,
            'store' => $inquiry->store?->only(['id', 'name', 'slug']),
            'assigned_agent' => $inquiry->assignee?->only(['id', 'name', 'email']),
            'read_at' => $inquiry->read_at?->toIso8601String(),
            'admin_notification_status' => $inquiry->admin_notification_status,
            'created_at' => $inquiry->created_at?->toIso8601String(),
            'updated_at' => $inquiry->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function message(MarketplaceContactInquiryMessage $message): array
    {
        return [
            'id' => $message->id,
            'type' => $message->message_type,
            'body' => $message->body,
            'sender' => $message->sender?->only(['id', 'name', 'email']),
            'delivery_status' => $message->delivery_status,
            'delivery_error' => $message->delivery_error,
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function assignees(int $tenantId): array
    {
        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('user', fn (Builder $user): Builder => $user->where('status', 'active'))
            ->with(['user:id,name,email', 'role:id,name'])
            ->get()
            ->map(fn (TenantMembership $membership): array => [
                'id' => $membership->user_id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'role' => $membership->role?->name,
            ])->values()->all();
    }

    private function meta($paginator): array
    {
        return ['total' => $paginator->total(), 'per_page' => $paginator->perPage(), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage()];
    }
}
