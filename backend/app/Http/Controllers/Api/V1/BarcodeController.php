<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\BulkGenerateBarcodeRequest;
use App\Http\Requests\Inventory\GenerateBarcodeRequest;
use App\Http\Requests\Inventory\UpdateBarcodeRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\InventoryLocation;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
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
