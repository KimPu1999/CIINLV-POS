<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\MarketplaceReviewResource;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceReview;
use App\Models\MarketplaceReviewModerationHistory;
use App\Models\MarketplaceReviewReport;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use App\Support\PublicMediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MarketplaceReviewController
{
    public function customerIndex(Request $request): JsonResponse
    {
        $reviews = MarketplaceReview::query()
            ->where('customer_user_id', $request->user()->id)
            ->with(['listing.product.images', 'product', 'customer', 'orderItem', 'moderator', 'moderationHistory.moderator'])
            ->latest()
            ->paginate($this->perPage($request));

        return $this->paginated($reviews);
    }

    public function eligible(Request $request): JsonResponse
    {
        $items = $this->eligibleItems($request->user()->id)
            ->with(['listing.product.images', 'product', 'vendorOrder.order'])
            ->paginate($this->perPage($request));

        $data = $items->getCollection()->map(fn (MarketplaceOrderItem $item): array => [
            'id' => $item->uuid,
            'product' => [
                'id' => $item->listing?->uuid,
                'name' => $item->product?->name ?? $item->product_name_snapshot,
                'image_url' => $item->product?->images?->first()?->storage_path ? PublicMediaUrl::make($item->product->images->first()->storage_path) : null,
            ],
            'order_number' => $item->vendorOrder?->order?->order_number,
            'purchased_at' => $item->vendorOrder?->order?->created_at?->toIso8601String(),
            'reviewed' => false,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => $this->meta($items),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate([
            'order_item_id' => ['required', 'uuid'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:160'],
            'comment' => ['required', 'string', 'min:3', 'max:5000'],
        ]);
        $ownedItem = MarketplaceOrderItem::query()->where('uuid', $data['order_item_id'])->whereHas('order', fn (Builder $order) => $order->where('customer_user_id', $request->user()->id))->first();
        if ($ownedItem && MarketplaceReview::query()->where('marketplace_order_item_id', $ownedItem->id)->where('customer_user_id', $request->user()->id)->exists()) {
            throw ValidationException::withMessages(['order_item_id' => ['This purchased item has already been reviewed.']]);
        }
        $item = $this->eligibleItems($request->user()->id)->where('marketplace_order_items.uuid', $data['order_item_id'])->firstOrFail();

        $review = MarketplaceReview::create([
            'tenant_id' => $item->tenant_id,
            'marketplace_listing_id' => $item->marketplace_listing_id,
            'product_id' => $item->product_id,
            'marketplace_order_item_id' => $item->id,
            'customer_user_id' => $request->user()->id,
            'rating' => $data['rating'],
            'title' => $this->plainText($data['title'] ?? null),
            'comment' => $this->plainText($data['comment']),
            'status' => MarketplaceReview::STATUS_PENDING,
            'verified_purchase' => true,
        ]);
        $auditLogger->record('marketplace.review_submitted', $request->user(), metadata: ['review_id' => $review->uuid, 'tenant_id' => $item->tenant_id]);

        return response()->json(['success' => true, 'data' => new MarketplaceReviewResource($review->load(['listing.product.images', 'product', 'customer', 'orderItem']))], 201);
    }

    public function report(Request $request, MarketplaceReview $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        MarketplaceReviewReport::create(['marketplace_review_id' => $review->id, 'reporter_user_id' => $request->user()->id, 'reason' => $this->plainText($data['reason'])]);

        return response()->json(['success' => true, 'message' => 'Review report submitted.']);
    }

    public function businessIndex(Request $request, TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $query = $this->tenantQuery($tenantId)
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $query->where(function (Builder $search) use ($term): void {
                    $search->where('marketplace_reviews.comment', 'ilike', $term)
                        ->orWhere('marketplace_reviews.title', 'ilike', $term)
                        ->orWhereHas('product', fn (Builder $product) => $product->where('products.name', 'ilike', $term))
                        ->orWhereHas('customer', fn (Builder $customer) => $customer->where('users.name', 'ilike', $term)->orWhere('users.email', 'ilike', $term));
                });
            })
            ->when($request->filled('rating'), fn (Builder $query) => $query->where('rating', (int) $request->input('rating')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('from'), fn (Builder $query) => $query->whereDate('marketplace_reviews.created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $query) => $query->whereDate('marketplace_reviews.created_at', '<=', $request->date('to')));
        $stats = [
            'total' => (clone $query)->count(),
            'average_rating' => number_format((float) ((clone $query)->avg('rating') ?? 0), 2, '.', ''),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'approved' => (clone $query)->where('status', 'approved')->count(),
            'rejected_hidden' => (clone $query)->whereIn('status', ['rejected', 'hidden'])->count(),
        ];
        $reviews = $query->with(['listing.product.images', 'product', 'customer', 'orderItem', 'moderator'])->latest()->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => MarketplaceReviewResource::collection($reviews), 'meta' => [...$this->meta($reviews), 'summary' => $stats]]);
    }

    public function businessShow(string $review, TenantContext $context): JsonResponse
    {
        $model = $this->tenantQuery($context->tenant()->id)->with(['listing.product.images', 'product', 'customer', 'orderItem', 'moderator', 'moderationHistory.moderator'])->where('marketplace_reviews.uuid', $review)->firstOrFail();

        return response()->json(['success' => true, 'data' => new MarketplaceReviewResource($model)]);
    }

    public function publicIndex(MarketplaceListing $listing): JsonResponse
    {
        $listing = MarketplaceListing::query()->public()->whereKey($listing->id)->firstOrFail();
        $query = MarketplaceReview::query()->approved()->where('marketplace_listing_id', $listing->id);
        $reviews = (clone $query)->with(['customer', 'product'])->latest()->paginate(10);
        $distribution = collect(range(1, 5))->mapWithKeys(fn (int $rating): array => [$rating => (clone $query)->where('rating', $rating)->count()]);

        $meta = $this->meta($reviews);
        $meta['summary'] = ['average_rating' => number_format((float) ((clone $query)->avg('rating') ?? 0), 2, '.', ''), 'total_reviews' => (clone $query)->count(), 'distribution' => $distribution];

        return response()->json(['success' => true, 'data' => MarketplaceReviewResource::collection($reviews), 'meta' => $meta]);
    }

    public function moderate(Request $request, string $review, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected', 'hidden'])], 'reason' => ['nullable', 'string', 'max:500']]);
        $model = MarketplaceReview::query()->where('uuid', $review)->firstOrFail();
        $previous = $model->status;
        if ($previous === $data['status'] && ! $request->filled('reason')) {
            return response()->json(['success' => true, 'data' => new MarketplaceReviewResource($model->load(['listing.product.images', 'product', 'customer', 'orderItem', 'moderator', 'moderationHistory.moderator']))]);
        }
        DB::transaction(function () use ($data, $model, $previous, $request): void {
            $model->update(['status' => $data['status'], 'moderated_by' => $request->user()->id, 'moderation_reason' => $this->plainText($data['reason'] ?? null), 'moderated_at' => now()]);
            MarketplaceReviewModerationHistory::create(['marketplace_review_id' => $model->id, 'tenant_id' => $model->tenant_id, 'moderator_user_id' => $request->user()->id, 'previous_status' => $previous, 'new_status' => $data['status'], 'reason' => $this->plainText($data['reason'] ?? null)]);
        });
        $auditLogger->record('marketplace.review_moderated', $request->user(), subject: $model, metadata: ['previous_status' => $previous, 'new_status' => $data['status'], 'reason' => $data['reason'] ?? null]);

        return response()->json(['success' => true, 'data' => new MarketplaceReviewResource($model->refresh()->load(['listing.product.images', 'product', 'customer', 'orderItem', 'moderator', 'moderationHistory.moderator']))]);
    }

    private function eligibleItems(int $customerId): Builder
    {
        return MarketplaceOrderItem::query()
            ->whereHas('order', fn (Builder $order) => $order->where('customer_user_id', $customerId)->whereNotIn('status', ['cancelled', 'refunded']))
            ->whereHas('vendorOrder', fn (Builder $vendor) => $vendor->where('status', 'delivered'))
            ->whereNotNull('marketplace_listing_id')
            ->whereNotNull('product_id')
            ->whereDoesntHave('reviews', fn (Builder $review) => $review->where('customer_user_id', $customerId));
    }

    private function tenantQuery(int $tenantId): Builder
    {
        return MarketplaceReview::query()->where('marketplace_reviews.tenant_id', $tenantId);
    }

    private function plainText(?string $value): ?string
    {
        return $value === null ? null : trim(strip_tags($value));
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    private function meta(mixed $paginator): array
    {
        return ['total' => $paginator->total(), 'per_page' => $paginator->perPage(), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage()];
    }

    private function paginated(mixed $reviews): JsonResponse
    {
        return response()->json(['success' => true, 'data' => MarketplaceReviewResource::collection($reviews), 'meta' => $this->meta($reviews)]);
    }
}
