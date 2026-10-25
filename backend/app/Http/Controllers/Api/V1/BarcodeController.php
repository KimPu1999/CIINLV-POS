<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\BulkGenerateBarcodeRequest;
use App\Http\Requests\Inventory\GenerateBarcodeRequest;
use App\Http\Requests\Inventory\UpdateBarcodeRequest;
use App\Http\Resources\ProductVariantResource;
use App\Jobs\ProcessBarcodeBatch;
use App\Models\BarcodeBatch;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\BarcodeBatchService;
use App\Services\BarcodeService;
use App\Services\ReportScopeService;
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BarcodeController
{
    public function show(string $barcode, Request $request, TenantContext $context, ReportScopeService $scope): JsonResponse
    {
        $variant = ProductVariant::where('tenant_id', $context->tenant()->id)->where('barcode', $barcode)->where('status', 'active')->with('product')->first();
        abort_unless($variant, 404, 'No active product variant matches this barcode.');

        if ($request->filled('branch_id')) {
            $storeIds = $scope->storeIds($request->user(), $context->tenant(), (string) $request->input('branch_id'));
            $locationIds = InventoryLocation::query()->where('tenant_id', $context->tenant()->id)->whereIn('store_id', $storeIds)->pluck('id');
            $variant->load(['balances' => fn ($balance) => $balance->whereIn('inventory_balances.inventory_location_id', $locationIds)]);
        }

        return response()->json(['success' => true, 'data' => new ProductVariantResource($variant)]);
    }

    public function statistics(TenantContext $context): JsonResponse
    {
        $tenantId = $context->tenant()->id;
        $total = ProductVariant::where('tenant_id', $tenantId)->where('status', 'active')->count();
        $withBarcode = ProductVariant::where('tenant_id', $tenantId)->where('status', 'active')->whereNotNull('barcode')->where('barcode', '!=', '')->count();

        return response()->json(['success' => true, 'data' => ['total_variants' => $total, 'with_barcode' => $withBarcode, 'missing_barcode' => $total - $withBarcode, 'duplicate_conflicts' => DB::table('product_variants')->where('tenant_id', $tenantId)->whereNotNull('barcode')->select('barcode')->groupBy('barcode')->havingRaw('COUNT(*) > 1')->count()]]);
    }

    public function brands(Request $request, TenantContext $context): JsonResponse
    {
        $this->requireManager($request, $context);
        $brands = Product::query()
            ->where('tenant_id', $context->tenant()->id)
            ->whereNotNull('brand')
            ->whereRaw("trim(brand) <> ''")
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->values();

        return response()->json(['success' => true, 'data' => $brands]);
    }

    public function catalog(Request $request, TenantContext $context): JsonResponse
    {
        $this->requireManager($request, $context);
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
            'search' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'string', 'max:36'],
            'barcode_status' => ['nullable', 'in:all,assigned,missing'],
            'product_status' => ['nullable', 'in:active,inactive,archived'],
            'sort' => ['nullable', 'in:product_name,brand,sku,barcode,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = ProductVariant::query()
            ->select('product_variants.*')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('product_variants.tenant_id', $context->tenant()->id)
            ->with(['product.category']);

        $query->when($request->filled('search'), function ($query) use ($request): void {
            $term = trim((string) $request->input('search'));
            $query->where(function ($query) use ($term): void {
                $query->where('product_variants.sku', 'ilike', '%'.$term.'%')
                    ->orWhere('product_variants.barcode', 'ilike', '%'.$term.'%')
                    ->orWhere('products.name', 'ilike', '%'.$term.'%')
                    ->orWhere('products.brand', 'ilike', '%'.$term.'%');
            });
        });
        $query->when($request->filled('brand'), function ($query) use ($request): void {
            if ($request->input('brand') === '__unbranded__') {
                $query->where(fn ($brand) => $brand->whereNull('products.brand')->orWhereRaw("trim(products.brand) = ''"));
            } else {
                $query->where('products.brand', (string) $request->input('brand'));
            }
        });
        $query->when($request->filled('category_id'), fn ($query) => $query->where('categories.uuid', (string) $request->input('category_id')));
        $query->when($request->filled('product_status'), fn ($query) => $query->where('products.status', (string) $request->input('product_status')));
        $query->when($request->input('barcode_status') === 'assigned', fn ($query) => $query->whereNotNull('product_variants.barcode')->where('product_variants.barcode', '<>', ''));
        $query->when($request->input('barcode_status') === 'missing', fn ($query) => $query->where(fn ($barcode) => $barcode->whereNull('product_variants.barcode')->orWhere('product_variants.barcode', '')));

        $sort = $request->input('sort', 'product_name');
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $sortColumn = match ($sort) {
            'brand' => 'products.brand',
            'sku' => 'product_variants.sku',
            'barcode' => 'product_variants.barcode',
            'created_at' => 'product_variants.created_at',
            default => 'products.name',
        };
        $variants = $query->orderBy($sortColumn, $direction)->orderBy('product_variants.id')->paginate(min(max((int) $request->input('per_page', 25), 1), 250));

        return response()->json([
            'success' => true,
            'data' => collect($variants->items())->map(function (ProductVariant $variant): array {
                $attributes = is_array($variant->attributes) ? $variant->attributes : [];

                return [
                    'id' => $variant->uuid,
                    'product_id' => $variant->product?->uuid,
                    'product_name' => $variant->product?->name,
                    'brand' => $variant->product?->brand,
                    'category' => $variant->product?->category?->name,
                    'sku' => $variant->sku,
                    'variant' => $attributes['size'] ?? ($attributes['color'] ?? null),
                    'attributes' => $attributes,
                    'barcode' => $variant->barcode,
                    'barcode_type' => $variant->barcode_type,
                    'selling_price' => $variant->selling_price,
                    'status' => $variant->status,
                    'product_status' => $variant->product?->status,
                ];
            })->values(),
            'meta' => [
                'total' => $variants->total(),
                'per_page' => $variants->perPage(),
                'current_page' => $variants->currentPage(),
                'last_page' => $variants->lastPage(),
            ],
        ]);
    }

    public function generate(GenerateBarcodeRequest $request, ProductVariant $variant, TenantContext $context, AuditLogger $auditLogger): JsonResponse
    {
        abort_unless((int) $variant->tenant_id === (int) $context->tenant()->id, 404);
        abort_unless($request->user()->memberships()->where('tenant_id', $context->tenant()->id)->where('status', 'active')->whereHas('role', fn ($role) => $role->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);

        $updated = DB::transaction(function () use ($variant, $request, $context): ProductVariant {
            $locked = ProductVariant::query()->whereKey($variant->getKey())->where('tenant_id', $context->tenant()->id)->lockForUpdate()->firstOrFail();
            if ($locked->barcode) {
                throw new ConflictHttpException('This variant already has a barcode. Clear it explicitly before generating a replacement.');
            }
            $type = $request->input('type', 'internal');
            do {
                $barcode = 'CIINLV-'.$context->tenant()->id.'-'.$locked->getKey().'-'.strtoupper(bin2hex(random_bytes(3)));
            } while (ProductVariant::where('tenant_id', $context->tenant()->id)->where('barcode', $barcode)->exists());
            $locked->update(['barcode' => $barcode, 'barcode_type' => $type]);

            return $locked->fresh('product');
        });
        $auditLogger->record('inventory.barcode_generated', $request->user(), $context->tenant(), $updated, ['variant_id' => $updated->uuid, 'barcode_type' => $updated->barcode_type]);

        return response()->json(['success' => true, 'data' => new ProductVariantResource($updated)]);
    }

    public function update(UpdateBarcodeRequest $request, ProductVariant $variant, TenantContext $context, AuditLogger $auditLogger, BarcodeService $barcodeService): JsonResponse
    {
        abort_unless((int) $variant->tenant_id === (int) $context->tenant()->id, 404);
        $this->requireManager($request, $context);

        $barcode = $barcodeService->validate($request->input('barcode'), $request->input('type', 'manual'));
        $type = $barcode ? $request->input('type', 'manual') : null;
        $updated = DB::transaction(function () use ($variant, $context, $barcode, $type): ProductVariant {
            $locked = ProductVariant::query()->whereKey($variant->getKey())->where('tenant_id', $context->tenant()->id)->lockForUpdate()->firstOrFail();
            if ($barcode && ProductVariant::query()->where('tenant_id', $context->tenant()->id)->where('barcode', $barcode)->where('id', '!=', $locked->getKey())->where('status', 'active')->exists()) {
                throw new ConflictHttpException('The barcode is already in use in this business.');
            }
            $locked->update(['barcode' => $barcode, 'barcode_type' => $type]);

            return $locked->fresh('product');
        });
        $auditLogger->record($barcode ? 'inventory.barcode_assigned' : 'inventory.barcode_cleared', $request->user(), $context->tenant(), $updated, ['variant_id' => $updated->uuid, 'barcode_type' => $updated->barcode_type]);

        return response()->json(['success' => true, 'data' => new ProductVariantResource($updated)]);
    }

    public function bulkGenerate(BulkGenerateBarcodeRequest $request, TenantContext $context, AuditLogger $auditLogger, BarcodeService $barcodeService): JsonResponse
    {
        $this->requireManager($request, $context);
        $tenantId = (int) $context->tenant()->id;
        $type = $request->input('type', 'internal');
        $requested = array_values(array_unique($request->input('variant_ids', [])));
        $assignments = collect($request->input('assignments', []))->keyBy('variant_id');

        $result = DB::transaction(function () use ($requested, $tenantId, $type, $assignments, $request, $barcodeService): array {
            $variants = ProductVariant::query()->where('tenant_id', $tenantId)->whereIn('uuid', $requested)->with('product')->lockForUpdate()->get()->keyBy('uuid');
            abort_if($variants->count() !== count($requested), 422, 'One or more selected variants are not available in this business.');
            $generated = [];
            $skipped = [];
            $preview = [];
            $seen = [];
            foreach ($requested as $uuid) {
                $variant = $variants->get($uuid);
                if ($variant->barcode) {
                    $skipped[] = $variant->uuid;

                    continue;
                }
                $assignment = $assignments->get($uuid);
                $barcode = $assignment['barcode'] ?? $this->newInternalBarcode($tenantId, $variant);
                $barcode = $barcodeService->validate($barcode, $type);
                abort_if(isset($seen[$barcode]), 409, 'The proposed barcodes contain a duplicate value.');
                abort_if(ProductVariant::query()->where('tenant_id', $tenantId)->where('barcode', $barcode)->where('id', '!=', $variant->getKey())->where('status', 'active')->exists(), 409, 'One of the proposed barcodes is already in use in this business.');
                $seen[$barcode] = true;
                $preview[] = ['variant_id' => $variant->uuid, 'barcode' => $barcode, 'sku' => $variant->sku, 'product_name' => $variant->product?->name, 'size' => is_array($variant->attributes) ? ($variant->attributes['size'] ?? null) : null];
                if ($request->boolean('preview')) {
                    continue;
                }
                $variant->update(['barcode' => $barcode, 'barcode_type' => $type]);
                $generated[] = $variant->fresh('product');
            }

            return ['generated' => $generated, 'skipped' => $skipped, 'preview' => $preview];
        });

        if ($request->boolean('preview')) {
            return response()->json(['success' => true, 'data' => ['preview' => $result['preview'], 'skipped' => $result['skipped']]]);
        }

        foreach ($result['generated'] as $variant) {
            $auditLogger->record('inventory.barcode_generated', $request->user(), $context->tenant(), $variant, ['variant_id' => $variant->uuid, 'barcode_type' => $variant->barcode_type, 'bulk' => true]);
        }

        return response()->json(['success' => true, 'data' => ['generated' => ProductVariantResource::collection(collect($result['generated'])), 'skipped' => $result['skipped'], 'preview' => $result['preview']]]);
    }

    public function batchIndex(Request $request, TenantContext $context): JsonResponse
    {
        $this->requireManager($request, $context);
        $batches = BarcodeBatch::query()->where('tenant_id', $context->tenant()->id)->latest()->paginate(min((int) $request->input('per_page', 20), 100));

        return response()->json(['success' => true, 'data' => collect($batches->items())->map(fn (BarcodeBatch $batch) => $this->batchData($batch)), 'meta' => ['current_page' => $batches->currentPage(), 'last_page' => $batches->lastPage(), 'per_page' => $batches->perPage(), 'total' => $batches->total()]]);
    }

    public function createBatch(Request $request, TenantContext $context, BarcodeBatchService $service): JsonResponse
    {
        $this->requireManager($request, $context);
        $data = $request->validate([
            'generation_mode' => ['required', 'in:unique,copies,manual_csv'],
            'barcode_type' => ['nullable', 'in:internal,code-128,ean-13,ean-8,upc-a,manual'],
            'label_template' => ['nullable', 'in:40x25,50x30,a4'],
            'variant_ids' => ['nullable', 'array', 'max:100000'],
            'variant_ids.*' => ['uuid'],
            'select_all' => ['sometimes', 'boolean'],
            'search' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'string', 'max:36'],
            'barcode_status' => ['nullable', 'in:all,assigned,missing'],
            'product_status' => ['nullable', 'in:active,inactive,archived'],
            'variant_id' => ['required_if:generation_mode,copies', 'uuid'],
            'quantity' => ['required_if:generation_mode,copies', 'integer', 'min:1', 'max:100000'],
            'prefix' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]*$/'],
            'starting_sequence' => ['nullable', 'integer', 'min:1', 'max:999999999'],
            'show_business_name' => ['sometimes', 'boolean'],
            'show_product_name' => ['sometimes', 'boolean'],
            'show_price' => ['sometimes', 'boolean'],
            'overwrite' => ['sometimes', 'boolean'],
            'confirm_overwrite' => ['sometimes', 'boolean'],
            'allow_partial' => ['sometimes', 'boolean'],
            'file' => ['required_if:generation_mode,manual_csv', 'file', 'max:20480', 'mimes:csv,txt'],
        ]);
        $data['idempotency_key'] = trim((string) $request->header('Idempotency-Key', $request->input('idempotency_key', ''))) ?: null;
        $data['confirm_overwrite'] = $request->boolean('confirm_overwrite');
        abort_if($data['generation_mode'] === 'unique' && ! $request->boolean('select_all') && empty($data['variant_ids']), 422, 'Select at least one variant or enable all eligible variants.');
        abort_if($data['idempotency_key'] !== null && strlen((string) $data['idempotency_key']) > 150, 422, 'The idempotency key is too long.');
        $batch = $service->create($data, $context->tenant(), $request->user(), $request->file('file'));
        if ($batch->generation_mode === 'manual_csv' && $batch->status === 'pending_review') {
            $batch = $service->previewCsv($batch);
        } elseif ($batch->status === 'pending' && ! ($batch->options['queued'] ?? false)) {
            $batch->update(['options' => array_merge($batch->options ?? [], ['queued' => true])]);
            ProcessBarcodeBatch::dispatch($batch->id)->onQueue('barcode-batches')->afterCommit();
        }

        return response()->json(['success' => true, 'message' => $batch->status === 'pending_review' ? 'CSV preview is ready for review.' : 'Barcode batch queued.', 'data' => $this->batchData($batch)], $batch->status === 'pending_review' ? 200 : 202);
    }

    public function startBatch(BarcodeBatch $batch, Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeBatch($batch, $request, $context);
        abort_unless($batch->status === 'pending_review', 409, 'This batch is no longer awaiting review.');
        $allowPartial = $request->boolean('allow_partial');
        abort_if(($batch->error_summary['count'] ?? 0) > 0 && ! $allowPartial, 422, 'Review or explicitly allow the CSV errors before importing.');
        $batch->update(['status' => 'pending', 'options' => array_merge($batch->options ?? [], ['allow_partial' => $allowPartial, 'queued' => true])]);
        ProcessBarcodeBatch::dispatch($batch->id)->onQueue('barcode-batches')->afterCommit();

        return response()->json(['success' => true, 'message' => 'Barcode batch queued.', 'data' => $this->batchData($batch->fresh())], 202);
    }

    public function showBatch(BarcodeBatch $batch, Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeBatch($batch, $request, $context);

        return response()->json(['success' => true, 'data' => $this->batchData($batch)]);
    }

    public function batchLabels(BarcodeBatch $batch, Request $request, TenantContext $context, BarcodeBatchService $service): JsonResponse
    {
        $this->authorizeBatch($batch, $request, $context);
        $offset = max(0, (int) $request->input('offset', 0));
        $limit = min(250, max(1, (int) $request->input('limit', 100)));

        return response()->json(['success' => true, 'data' => $service->labels($batch, $offset, $limit)]);
    }

    public function downloadBatchCsv(BarcodeBatch $batch, Request $request, TenantContext $context, BarcodeBatchService $service)
    {
        $this->authorizeBatch($batch, $request, $context);

        return response()->streamDownload($service->csvStream($batch), 'barcode-batch-'.$batch->uuid.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function cancelBatch(BarcodeBatch $batch, Request $request, TenantContext $context): JsonResponse
    {
        $this->authorizeBatch($batch, $request, $context);
        abort_unless(in_array($batch->status, ['pending', 'pending_review', 'processing'], true), 409, 'This batch cannot be cancelled.');
        $batch->update(['status' => 'cancelled', 'cancelled_at' => now(), 'completed_at' => now()]);

        return response()->json(['success' => true, 'message' => 'Barcode batch cancelled.', 'data' => $this->batchData($batch->fresh())]);
    }

    private function authorizeBatch(BarcodeBatch $batch, Request $request, TenantContext $context): void
    {
        $this->requireManager($request, $context);
        abort_unless((int) $batch->tenant_id === (int) $context->tenant()->id, 404);
    }

    private function batchData(BarcodeBatch $batch): array
    {
        $data = $batch->toArray();
        $data['id'] = $batch->uuid;
        $data['progress'] = $batch->requested_label_count > 0 ? round(($batch->completed_label_count / $batch->requested_label_count) * 100, 2) : 0;

        return $data;
    }

    private function requireManager(Request $request, TenantContext $context): void
    {
        abort_unless($request->user()->memberships()->where('tenant_id', $context->tenant()->id)->where('status', 'active')->whereHas('role', fn ($role) => $role->whereIn('name', ['business_admin', 'inventory_manager']))->exists(), 403);
    }

    private function newInternalBarcode(int $tenantId, ProductVariant $variant): string
    {
        do {
            $barcode = 'CIINLV-'.$tenantId.'-'.$variant->getKey().'-'.strtoupper(bin2hex(random_bytes(3)));
        } while (ProductVariant::query()->where('tenant_id', $tenantId)->where('barcode', $barcode)->exists());

        return $barcode;
    }
}
