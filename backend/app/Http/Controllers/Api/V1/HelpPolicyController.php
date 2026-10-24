<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FaqEntry;
use App\Models\PolicyDocument;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HelpPolicyController
{
    public function policies(Request $request): JsonResponse
    {
        $request->validate(['locale' => ['nullable', 'in:en,my'], 'type' => ['nullable', 'string', 'max:80']]);
        $query = PolicyDocument::query()->with('publisher:id,name')->when($request->filled('locale'), fn (Builder $query) => $query->where('locale', $request->string('locale')))->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->string('type')));
        if (! $request->user()->isSuperAdmin()) {
            $query->where('status', 'published');
        }
        $documents = $query->latest('updated_at')->paginate(25);

        return response()->json(['success' => true, 'data' => $documents->getCollection()->map(fn (PolicyDocument $policy) => $this->policy($policy))->values(), 'meta' => $this->meta($documents)]);
    }

    public function storePolicy(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'string', 'max:80'], 'version' => ['required', 'string', 'max:40', Rule::unique('policy_documents')->where(fn ($query) => $query->where('type', $request->string('type'))->where('locale', $request->string('locale')))], 'locale' => ['required', 'in:en,my'], 'title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string', 'max:100000']]);
        $policy = PolicyDocument::create([...$data, 'status' => 'draft', 'created_by' => $request->user()->id]);
        $auditLogger->record('platform.policy_draft_created', $request->user(), subject: $policy, metadata: ['type' => $policy->type, 'version' => $policy->version, 'locale' => $policy->locale]);

        return response()->json(['success' => true, 'data' => $this->policy($policy)], 201);
    }

    public function updatePolicy(Request $request, PolicyDocument $policy, AuditLogger $auditLogger): JsonResponse
    {
        abort_if($policy->status === 'published', 409, 'Published policies are immutable; create a new version.');
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'content' => ['sometimes', 'string', 'max:100000']]);
        $policy->update($data);
        $auditLogger->record('platform.policy_draft_updated', $request->user(), subject: $policy, metadata: ['type' => $policy->type, 'version' => $policy->version, 'locale' => $policy->locale]);

        return response()->json(['success' => true, 'data' => $this->policy($policy->refresh())]);
    }

    public function publishPolicy(Request $request, PolicyDocument $policy, AuditLogger $auditLogger): JsonResponse
    {
        abort_if($policy->status === 'published', 409, 'This policy version is already published.');
        DB::transaction(function () use ($policy, $request): void {
            PolicyDocument::query()->where('type', $policy->type)->where('locale', $policy->locale)->where('status', 'published')->update(['status' => 'archived', 'archived_at' => now()]);
            $policy->update(['status' => 'published', 'published_by' => $request->user()->id, 'published_at' => now(), 'effective_at' => $policy->effective_at ?: now()]);
        });
        $auditLogger->record('platform.policy_published', $request->user(), subject: $policy, metadata: ['type' => $policy->type, 'version' => $policy->version, 'locale' => $policy->locale]);

        return response()->json(['success' => true, 'data' => $this->policy($policy->refresh())]);
    }

    public function faqs(Request $request): JsonResponse
    {
        $request->validate(['locale' => ['nullable', 'in:en,my'], 'category' => ['nullable', 'string', 'max:80'], 'search' => ['nullable', 'string', 'max:120']]);
        $query = FaqEntry::query()->when($request->filled('locale'), fn (Builder $query) => $query->where('locale', $request->string('locale')))->when($request->filled('category'), fn (Builder $query) => $query->where('category', $request->string('category')))->when($request->filled('search'), fn (Builder $query) => $query->where(fn (Builder $search) => $search->where('question', 'ilike', '%'.$request->string('search').'%')->orWhere('answer', 'ilike', '%'.$request->string('search').'%')));
        if (! $request->user()->isSuperAdmin()) {
            $query->where('status', 'published');
        }
        $faqs = $query->orderBy('display_order')->latest('updated_at')->paginate(50);

        return response()->json(['success' => true, 'data' => $faqs->getCollection()->values(), 'meta' => $this->meta($faqs)]);
    }

    public function storeFaq(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate(['category' => ['required', 'string', 'max:80'], 'question' => ['required', 'string', 'max:255'], 'answer' => ['required', 'string', 'max:100000'], 'locale' => ['required', 'in:en,my'], 'status' => ['sometimes', 'in:draft,published'], 'display_order' => ['sometimes', 'integer', 'min:0', 'max:99999']]);
        $faq = FaqEntry::create([...$data, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'published_at' => ($data['status'] ?? 'draft') === 'published' ? now() : null]);
        $auditLogger->record('platform.faq_created', $request->user(), subject: $faq, metadata: ['category' => $faq->category, 'locale' => $faq->locale, 'status' => $faq->status]);

        return response()->json(['success' => true, 'data' => $faq->fresh()], 201);
    }

    public function updateFaq(Request $request, FaqEntry $faq, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validate(['category' => ['sometimes', 'string', 'max:80'], 'question' => ['sometimes', 'string', 'max:255'], 'answer' => ['sometimes', 'string', 'max:100000'], 'status' => ['sometimes', 'in:draft,published'], 'display_order' => ['sometimes', 'integer', 'min:0', 'max:99999']]);
        $data['updated_by'] = $request->user()->id;
        if (($data['status'] ?? $faq->status) === 'published' && $faq->status !== 'published') {
            $data['published_at'] = now();
        }
        $faq->update($data);
        $auditLogger->record('platform.faq_updated', $request->user(), subject: $faq, metadata: ['category' => $faq->category, 'locale' => $faq->locale, 'status' => $faq->status]);

        return response()->json(['success' => true, 'data' => $faq->fresh()]);
    }

    public function destroyFaq(Request $request, FaqEntry $faq, AuditLogger $auditLogger): JsonResponse
    {
        $auditLogger->record('platform.faq_deleted', $request->user(), subject: $faq, metadata: ['category' => $faq->category, 'locale' => $faq->locale]);
        $faq->delete();

        return response()->json(['success' => true]);
    }

    private function policy(PolicyDocument $policy): array
    {
        return ['id' => $policy->id, 'type' => $policy->type, 'version' => $policy->version, 'locale' => $policy->locale, 'title' => $policy->title, 'content' => $policy->content, 'status' => $policy->status, 'effective_at' => $policy->effective_at?->toIso8601String(), 'published_at' => $policy->published_at?->toIso8601String(), 'published_by' => $policy->publisher?->only(['id', 'name']), 'updated_at' => $policy->updated_at?->toIso8601String()];
    }

    private function meta($paginator): array
    {
        return ['total' => $paginator->total(), 'per_page' => $paginator->perPage(), 'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage()];
    }
}
