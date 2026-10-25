<?php

namespace App\Http\Resources;

use App\Models\MarketplaceDispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceDisputeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var MarketplaceDispute $dispute */
        $dispute = $this->resource;
        return [
            'id' => $dispute->uuid,
            'reference' => $dispute->reference,
            'order_id' => $dispute->order?->uuid,
            'order_number' => $dispute->order?->order_number,
            'vendor_order_id' => $dispute->vendorOrder?->uuid,
            'vendor_order_number' => $dispute->vendorOrder?->vendor_order_number,
            'item' => $dispute->item ? ['id' => $dispute->item->uuid, 'name' => $dispute->item->product_name_snapshot, 'quantity' => $dispute->item->quantity] : null,
            'business' => $dispute->tenant ? ['id' => $dispute->tenant->uuid, 'name' => $dispute->tenant->name] : null,
            'customer' => $dispute->customer ? ['id' => $dispute->customer->id, 'name' => $dispute->customer->name, 'email' => $dispute->customer->email] : null,
            'category' => $dispute->category,
            'subject' => $dispute->subject,
            'description' => $dispute->description,
            'status' => $dispute->status,
            'priority' => $dispute->priority,
            'requested_resolution' => $dispute->requested_resolution,
            'resolution_note' => $dispute->resolution_note,
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'assigned_reviewer' => $dispute->reviewer?->only(['id', 'name', 'email']),
            'evidence' => $this->whenLoaded('evidence', fn () => $dispute->evidence->map(fn ($file): array => ['id' => $file->uuid, 'name' => $file->original_name, 'mime_type' => $file->mime_type, 'size_bytes' => $file->size_bytes, 'sha256' => $file->sha256, 'uploaded_by' => $file->uploader?->only(['id', 'name']), 'created_at' => $file->created_at?->toIso8601String()])),
            'messages' => $this->whenLoaded('messages', fn () => $dispute->messages->map(fn ($message): array => ['id' => $message->uuid, 'message' => $message->message, 'visibility' => $message->visibility, 'sender' => $message->sender?->only(['id', 'name']), 'created_at' => $message->created_at?->toIso8601String()])),
            'history' => $this->whenLoaded('history', fn () => $dispute->history->map(fn ($entry): array => ['from' => $entry->from_status, 'to' => $entry->to_status, 'reason' => $entry->reason, 'actor' => $entry->actor?->only(['id', 'name']), 'created_at' => $entry->created_at?->toIso8601String()])),
            'resolution' => $this->whenLoaded('resolution', fn () => $dispute->resolution ? ['id' => $dispute->resolution->uuid, 'type' => $dispute->resolution->type, 'amount' => $dispute->resolution->amount, 'currency_code' => $dispute->resolution->currency_code, 'note' => $dispute->resolution->note, 'refund_status' => $dispute->resolution->refund_status, 'approved_by' => $dispute->resolution->approver?->only(['id', 'name']), 'approved_at' => $dispute->resolution->approved_at?->toIso8601String()] : null),
            'created_at' => $dispute->created_at?->toIso8601String(),
            'updated_at' => $dispute->updated_at?->toIso8601String(),
        ];
    }
}
