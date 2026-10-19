<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Inventory\GenerateBarcodeRequest;
use App\Http\Resources\ProductVariantResource;
use App\Models\ProductVariant;
use App\Services\AuditLogger;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BarcodeController
{
    public function show(string $barcode, TenantContext $context): JsonResponse
    {
        $variant = ProductVariant::where('tenant_id', $context->tenant()->id)->where('barcode', $barcode)->where('status', 'active')->with('product')->first();
        abort_unless($variant, 404, 'No active product variant matches this barcode.');

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
}
