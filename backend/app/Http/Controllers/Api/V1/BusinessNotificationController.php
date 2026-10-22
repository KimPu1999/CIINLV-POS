<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\BusinessNotification;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessNotificationController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $query = $this->query($request, $context);
        $type = $request->string('type')->toString();
        if ($type === 'orders') {
            $query->whereIn('type', ['marketplace_order_created', 'marketplace_fulfillment_ready']);
        } elseif ($type === 'payments') {
            $query->where('type', 'marketplace_payment_received');
        } elseif ($type === 'inventory') {
            $query->whereIn('type', ['inventory_low_stock', 'inventory_out_of_stock']);
        } elseif ($type === 'unread') {
            $query->whereNull('read_at');
        }

        $notifications = $query->paginate(min(max((int) $request->input('per_page', 20), 1), 100));

        return response()->json([
            'success' => true,
            'data' => collect($notifications->items())->map(fn (BusinessNotification $notification): array => $this->payload($notification))->values(),
            'meta' => [
                'total' => $notifications->total(),
                'per_page' => $notifications->perPage(),
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request, TenantContext $context): JsonResponse
    {
        $count = $this->query($request, $context)->whereNull('read_at')->count();

        return response()->json(['success' => true, 'data' => ['count' => $count]]);
    }

    public function markRead(Request $request, BusinessNotification $notification, TenantContext $context): JsonResponse
    {
        $this->assertOwnership($request, $notification, $context);
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'data' => $this->payload($notification->refresh())]);
    }

    public function markAllRead(Request $request, TenantContext $context): JsonResponse
    {
        $count = $this->query($request, $context)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true, 'data' => ['updated' => $count]]);
    }

    private function query(Request $request, TenantContext $context)
    {
        return BusinessNotification::query()
            ->where('tenant_id', $context->tenant()->id)
            ->where('user_id', $request->user()->id)
            ->latest('created_at');
    }

    private function assertOwnership(Request $request, BusinessNotification $notification, TenantContext $context): void
    {
        abort_unless((int) $notification->tenant_id === (int) $context->tenant()->id && (int) $notification->user_id === (int) $request->user()->id, 404);
    }

    /** @return array<string, mixed> */
    private function payload(BusinessNotification $notification): array
    {
        return [
            'id' => $notification->uuid,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'priority' => $notification->priority,
            'data' => $notification->data ?: [],
            'related_url' => $notification->data['related_url'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
