<?php

namespace App\Services;

use App\Models\MarketplaceDispute;
use App\Models\MarketplaceDisputeEvidence;
use App\Models\MarketplaceDisputeMessage;
use App\Models\MarketplaceDisputeResolution;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceVendorOrder;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MarketplaceDisputeService
{
    public function __construct(private readonly BusinessNotificationService $notifications) {}
    private const TRANSITIONS = [
        'open' => ['awaiting_seller_response', 'under_review', 'rejected'],
        'awaiting_seller_response' => ['under_review', 'awaiting_customer_information'],
        'under_review' => ['awaiting_seller_response', 'awaiting_customer_information', 'resolved', 'rejected'],
        'awaiting_customer_information' => ['under_review', 'closed'],
        'resolved' => ['closed'],
        'rejected' => ['closed'],
        'closed' => [],
    ];

    public function create(MarketplaceOrder $order, User $customer, array $data, MarketplaceAuditLogger $audit): MarketplaceDispute
    {
        $eligibleCategories = $this->eligibleCategories($order);
        if ($eligibleCategories === []) {
            throw new UnprocessableEntityHttpException('This order is not eligible for a dispute.');
        }
        if (! in_array($data['category'], $eligibleCategories, true)) {
            throw new UnprocessableEntityHttpException('This dispute category is not available for the current order state.');
        }
        if ($order->created_at?->lt(now()->subDays((int) config('marketplace.disputes.filing_window_days', 30)))) {
            throw new UnprocessableEntityHttpException('The dispute filing window for this order has expired.');
        }

        $vendorOrder = $this->vendorOrder($order, $data['vendor_order_id'] ?? null);
        $item = $this->item($order, $data['item_id'] ?? null, $vendorOrder);
        $existing = MarketplaceDispute::query()->where('marketplace_order_id', $order->id)->where('customer_user_id', $customer->id)->whereNotIn('status', ['closed', 'rejected'])->exists();
        if ($existing) throw new ConflictHttpException('An active dispute already exists for this order.');

        $dispute = DB::transaction(function () use ($order, $customer, $data, $vendorOrder, $item, $audit): MarketplaceDispute {
            $dispute = MarketplaceDispute::create([
                'marketplace_order_id' => $order->id,
                'marketplace_vendor_order_id' => $vendorOrder?->id,
                'marketplace_order_item_id' => $item?->id,
                'tenant_id' => $vendorOrder?->tenant_id,
                'customer_user_id' => $customer->id,
                'category' => $data['category'],
                'subject' => trim(strip_tags($data['subject'])),
                'description' => trim(strip_tags($data['description'])),
                'requested_resolution' => $data['requested_resolution'],
                'status' => $vendorOrder ? 'awaiting_seller_response' : 'under_review',
                'priority' => 'normal',
            ]);
            $dispute->history()->create(['changed_by' => $customer->id, 'from_status' => null, 'to_status' => $dispute->status, 'reason' => 'Dispute submitted.']);
            $dispute->messages()->create(['sender_user_id' => $customer->id, 'visibility' => 'participants', 'message' => trim(strip_tags($data['description']))]);
            $audit->record('marketplace.dispute_created', $customer, $vendorOrder?->tenant, $dispute, ['reference' => $dispute->reference, 'order_id' => $order->uuid]);
            return $dispute;
        });

        $this->notifications->disputeUpdated($dispute, 'submitted');

        return $dispute->load(['order', 'vendorOrder.store', 'item', 'customer', 'evidence.uploader', 'messages.sender', 'history.actor', 'resolution']);
    }

    /** @return array<int, string> */
    public function eligibleCategories(MarketplaceOrder $order): array
    {
        $categories = match ($order->status) {
            'confirmed', 'processing' => ['payment_problem', 'order_problem', 'seller_not_responding'],
            'partially_shipped', 'shipped' => ['shipping_delay', 'tracking_problem', 'product_not_received', 'delivery_delay', 'payment_problem', 'seller_not_responding'],
            'delivered' => ['damaged_or_defective', 'wrong_product', 'missing_items', 'not_as_described'],
            'cancelled' => ['refund_problem'],
            default => [],
        };
        $methods = $order->vendorOrders()->get()->pluck('delivery_options')->all();
        $isPickup = collect($methods)->contains(fn ($options): bool => is_array($options) && in_array($options['method'] ?? null, ['take_away', 'pickup', 'store_pickup'], true));
        if ($isPickup) {
            $categories = array_values(array_unique(array_merge(['pickup_unavailable', 'pickup_delay'], $categories, ['wrong_product', 'damaged_or_defective', 'missing_items', 'not_as_described'])));
        }
        return $categories;
    }

    public function changeStatus(MarketplaceDispute $dispute, string $status, User $actor, ?string $reason, MarketplaceAuditLogger $audit): MarketplaceDispute
    {
        if (! in_array($status, MarketplaceDispute::STATUSES, true) || ! in_array($status, self::TRANSITIONS[$dispute->status] ?? [], true)) {
            throw new ConflictHttpException('This dispute status transition is not allowed.');
        }
        $from = $dispute->status;
        DB::transaction(function () use ($dispute, $status, $actor, $reason, $from, $audit): void {
            $dispute->update(['status' => $status, 'resolved_at' => in_array($status, ['resolved', 'rejected', 'closed'], true) ? now() : null]);
            $dispute->history()->create(['changed_by' => $actor->id, 'from_status' => $from, 'to_status' => $status, 'reason' => $reason ? trim(strip_tags($reason)) : null]);
            $audit->record('marketplace.dispute_status_changed', $actor, $dispute->tenant, $dispute, ['from' => $from, 'to' => $status, 'reason' => $reason]);
        });
        $this->notifications->disputeUpdated($dispute, 'status_changed');
        return $dispute->fresh()->load(['order', 'vendorOrder.store', 'item', 'customer', 'evidence.uploader', 'messages.sender', 'history.actor', 'resolution']);
    }

    public function addMessage(MarketplaceDispute $dispute, User $actor, string $message, bool $internal, MarketplaceAuditLogger $audit): MarketplaceDisputeMessage
    {
        if ($dispute->status === 'closed') throw new ConflictHttpException('Closed disputes cannot receive messages.');
        $created = $dispute->messages()->create(['sender_user_id' => $actor->id, 'visibility' => $internal ? 'internal' : 'participants', 'message' => trim(strip_tags($message))]);
        $this->notifications->disputeUpdated($dispute, 'message_received');
        $audit->record('marketplace.dispute_message_added', $actor, $dispute->tenant, $dispute, ['visibility' => $created->visibility]);
        return $created->load('sender');
    }

    public function addEvidence(MarketplaceDispute $dispute, User $actor, UploadedFile $file, MarketplaceAuditLogger $audit): MarketplaceDisputeEvidence
    {
        $maxFiles = (int) config('marketplace.disputes.max_evidence_files', 10);
        if ($dispute->evidence()->count() >= $maxFiles) throw new UnprocessableEntityHttpException('The evidence file limit has been reached.');
        $path = $file->store('marketplace/disputes/'.$dispute->uuid, 'private');
        $evidence = $dispute->evidence()->create(['uploaded_by' => $actor->id, 'disk' => 'private', 'path' => $path, 'original_name' => Str::limit($file->getClientOriginalName(), 255, ''), 'mime_type' => (string) $file->getMimeType(), 'size_bytes' => (int) $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath())]);
        $this->notifications->disputeUpdated($dispute, 'evidence_uploaded');
        $audit->record('marketplace.dispute_evidence_uploaded', $actor, $dispute->tenant, $dispute, ['evidence_id' => $evidence->uuid, 'sha256' => $evidence->sha256]);
        return $evidence->load('uploader');
    }

    public function resolve(MarketplaceDispute $dispute, User $actor, array $data, MarketplaceAuditLogger $audit): MarketplaceDispute
    {
        if ($dispute->resolution()->exists() || in_array($dispute->status, ['closed', 'rejected'], true)) throw new ConflictHttpException('This dispute already has a final decision.');
        if (! in_array('resolved', self::TRANSITIONS[$dispute->status] ?? [], true)) throw new ConflictHttpException('This dispute is not ready for a final decision.');
        if (! in_array($data['type'], MarketplaceDispute::RESOLUTIONS, true)) throw new UnprocessableEntityHttpException('Unsupported resolution type.');
        if (in_array($data['type'], ['full_refund', 'partial_refund'], true) && $dispute->order->payment_status !== 'paid') throw new UnprocessableEntityHttpException('A refund resolution requires verified payment. COD orders require manual refund confirmation.');
        $paid = (float) $dispute->order->payments()->where('status', 'paid')->sum('amount');
        $refunded = (float) $dispute->order->refundRequests()->where('status', 'approved')->sum('approved_amount');
        $available = max(0, $paid - $refunded);
        if ($data['type'] === 'partial_refund' && ((float) ($data['amount'] ?? 0) <= 0 || (float) $data['amount'] > $available)) throw new UnprocessableEntityHttpException('The requested refund exceeds the verified refundable balance.');
        if ($data['type'] === 'full_refund' && $available <= 0) throw new UnprocessableEntityHttpException('No verified refundable balance is available.');
        DB::transaction(function () use ($dispute, $actor, $data, $available, $audit): void {
            $key = 'dispute-resolution:'.$dispute->uuid;
            $dispute->resolution()->create(['approved_by' => $actor->id, 'type' => $data['type'], 'amount' => $data['amount'] ?? ($data['type'] === 'full_refund' ? $available : null), 'currency_code' => $dispute->order->currency_code, 'note' => trim(strip_tags($data['note'])), 'refund_status' => in_array($data['type'], ['full_refund', 'partial_refund'], true) ? 'pending_manual_provider_action' : 'not_applicable', 'refund_idempotency_key' => $key, 'approved_at' => now()]);
            $from = $dispute->status;
            $dispute->update(['status' => 'resolved', 'resolution_note' => trim(strip_tags($data['note'])), 'resolved_at' => now()]);
            $dispute->history()->create(['changed_by' => $actor->id, 'from_status' => $from, 'to_status' => 'resolved', 'reason' => 'Resolution approved.']);
            $audit->record('marketplace.dispute_resolution_approved', $actor, $dispute->tenant, $dispute, ['type' => $data['type'], 'amount' => $data['amount'] ?? null, 'refund_status' => $dispute->resolution->refund_status]);
        });
        $this->notifications->disputeUpdated($dispute, 'resolution_issued');
        return $dispute->fresh()->load(['order', 'vendorOrder.store', 'item', 'customer', 'evidence.uploader', 'messages.sender', 'history.actor', 'resolution.approver']);
    }

    private function vendorOrder(MarketplaceOrder $order, ?string $uuid): ?MarketplaceVendorOrder
    {
        if (! $uuid) return null;
        return $order->vendorOrders()->where('uuid', $uuid)->firstOrFail();
    }

    private function item(MarketplaceOrder $order, ?string $uuid, ?MarketplaceVendorOrder $vendorOrder): ?MarketplaceOrderItem
    {
        if (! $uuid) return null;
        return $order->items()->when($vendorOrder, fn ($query) => $query->where('marketplace_vendor_order_id', $vendorOrder->id))->where('uuid', $uuid)->firstOrFail();
    }
}
