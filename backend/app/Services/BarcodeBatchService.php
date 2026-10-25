<?php

namespace App\Services;

use App\Models\BarcodeBatch;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BarcodeBatchService
{
    public const CHUNK_SIZE = 250;

    public function __construct(private readonly BarcodeService $barcodes) {}

    public function create(array $data, Tenant $tenant, User $user, ?UploadedFile $file = null): BarcodeBatch
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;
        if ($idempotencyKey) {
            $existing = BarcodeBatch::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        $mode = $data['generation_mode'];
        $options = [
            'show_business_name' => (bool) ($data['show_business_name'] ?? true),
            'show_product_name' => (bool) ($data['show_product_name'] ?? true),
            'show_price' => (bool) ($data['show_price'] ?? false),
            'prefix' => trim((string) ($data['prefix'] ?? '')),
            'starting_sequence' => (int) ($data['starting_sequence'] ?? 1),
            'allow_partial' => (bool) ($data['allow_partial'] ?? false),
            'overwrite' => (bool) ($data['overwrite'] ?? false) && (bool) ($data['confirm_overwrite'] ?? false),
            'selection' => [
                'search' => trim((string) ($data['search'] ?? '')),
                'brand' => trim((string) ($data['brand'] ?? '')),
                'category_id' => trim((string) ($data['category_id'] ?? '')),
                'barcode_status' => (string) ($data['barcode_status'] ?? 'all'),
                'product_status' => (string) ($data['product_status'] ?? 'active'),
            ],
        ];
        $sourcePath = null;
        $requested = 0;

        if ($mode === 'unique') {
            $variantIds = array_values(array_unique($data['variant_ids'] ?? []));
            if (($data['select_all'] ?? false) === true) {
                $requested = $this->selectionQuery($tenant->id, $data)->count();
                abort_if($requested < 1, 422, 'No eligible variants match the current selection.');
                $options['select_all'] = true;
            } else {
                $authorized = ProductVariant::query()->where('tenant_id', $tenant->id)->whereIn('uuid', $variantIds)->count();
                abort_if($authorized !== count($variantIds), 422, 'One or more selected variants are not available in this business.');
                $requested = count($variantIds);
            }
        } elseif ($mode === 'copies') {
            $variant = ProductVariant::query()->where('tenant_id', $tenant->id)->where('uuid', $data['variant_id'])->where('status', 'active')->first();
            abort_unless($variant, 422, 'The selected variant is not available in this business.');
            abort_if(! $variant->barcode, 422, 'Assign a barcode to the selected variant before printing copies.');
            $options['variant_id'] = $variant->uuid;
            $options['copies'] = (int) $data['quantity'];
            $requested = (int) $data['quantity'];
        } else {
            abort_unless($file, 422, 'A CSV file is required for manual barcode assignment.');
        }

        $batch = BarcodeBatch::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'generation_mode' => $mode,
            'barcode_type' => $data['barcode_type'] ?? 'internal',
            'label_template' => $data['label_template'] ?? '50x30',
            'requested_label_count' => $requested,
            'status' => $mode === 'manual_csv' ? 'pending_review' : 'pending',
            'options' => $options,
            'idempotency_key' => $idempotencyKey,
        ]);

        if ($mode === 'unique') {
            $sourcePath = $this->sourcePath($batch, 'variants.txt');
            $path = Storage::disk('local')->path($sourcePath);
            Storage::disk('local')->makeDirectory(dirname($sourcePath));
            $output = fopen($path, 'wb');
            if (($data['select_all'] ?? false) === true) {
                foreach ($this->selectionQuery($tenant->id, $data)->cursor() as $variant) {
                    fwrite($output, $variant->uuid.PHP_EOL);
                }
            } else {
                foreach (array_values(array_unique($data['variant_ids'])) as $id) {
                    fwrite($output, $id.PHP_EOL);
                }
            }
            fclose($output);
        } elseif ($mode === 'manual_csv') {
            $sourcePath = $this->sourcePath($batch, 'assignments.csv');
            Storage::disk('local')->put($sourcePath, $file->get());
        }

        if ($sourcePath) {
            $batch->update(['source_path' => $sourcePath]);
        }

        return $batch->fresh();
    }

    public function previewCsv(BarcodeBatch $batch): BarcodeBatch
    {
        $this->ensureSource($batch);
        $stream = Storage::disk('local')->readStream($batch->source_path);
        $header = fgetcsv($stream);
        abort_if(! is_array($header), 422, 'The CSV file is empty.');
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        abort_if(! in_array('barcode', $header, true), 422, 'The CSV must include a barcode column.');
        abort_if(! array_intersect(['variant_id', 'sku'], $header), 422, 'The CSV must include variant_id or sku.');

        $errors = [];
        $valid = 0;
        $seen = [];
        $line = 1;
        while (($row = fgetcsv($stream)) !== false) {
            $line++;
            $record = $this->csvRecord($header, $row);
            $result = $this->validateCsvRecord($batch, $record, $seen);
            if ($result['error']) {
                $errors[] = ['line' => $line, 'message' => $result['error']];
            } else {
                $valid++;
                $seen[$result['barcode']] = true;
            }
        }
        fclose($stream);

        $batch->update([
            'requested_label_count' => $valid,
            'error_summary' => ['count' => count($errors), 'items' => array_slice($errors, 0, 200)],
            'options' => array_merge($batch->options ?? [], ['csv_rows' => $line - 1]),
        ]);

        return $batch->fresh();
    }

    public function process(int $batchId): void
    {
        $batch = BarcodeBatch::find($batchId);
        if (! $batch || in_array($batch->status, ['completed', 'cancelled'], true)) {
            return;
        }
        $batch->update(['status' => 'processing', 'started_at' => $batch->started_at ?: now(), 'completed_at' => null, 'completed_label_count' => 0, 'failed_label_count' => 0, 'manifest_path' => $this->manifestPath($batch)]);
        $manifest = $batch->manifest_path;
        Storage::disk('local')->delete($manifest);
        Storage::disk('local')->makeDirectory(dirname($manifest));
        $handle = fopen(Storage::disk('local')->path($manifest), 'ab');
        $completed = 0;
        $failed = 0;
        $errors = $batch->error_summary['items'] ?? [];

        try {
            if ($batch->generation_mode === 'copies') {
                $variant = ProductVariant::query()->where('tenant_id', $batch->tenant_id)->where('uuid', $batch->options['variant_id'] ?? '')->with('product')->first();
                if (! $variant || ! $variant->barcode) {
                    throw new RuntimeException('The selected variant no longer has an active barcode.');
                }
                fwrite($handle, $this->manifestLine($variant, (int) ($batch->options['copies'] ?? 0)));
                $completed = (int) ($batch->options['copies'] ?? 0);
                $batch->update(['completed_label_count' => $completed]);
            } elseif ($batch->generation_mode === 'unique') {
                $source = Storage::disk('local')->readStream($batch->source_path);
                $ids = [];
                $sequence = (int) ($batch->options['starting_sequence'] ?? 1);
                while (($id = fgets($source)) !== false) {
                    $id = trim($id);
                    if ($id === '') {
                        continue;
                    }
                    $ids[] = [$id, $sequence++];
                    if (count($ids) >= self::CHUNK_SIZE) {
                        [$done, $chunkFailed, $chunkErrors] = $this->processUniqueChunk($batch, $ids, $handle);
                        $completed += $done;
                        $failed += $chunkFailed;
                        $errors = array_merge($errors, $chunkErrors);
                        $batch->update(['completed_label_count' => $completed, 'failed_label_count' => $failed, 'error_summary' => ['count' => count($errors), 'items' => array_slice($errors, 0, 200)]]);
                        if ($this->cancelled($batch)) {
                            fclose($source);
                            fclose($handle);

                            return;
                        }
                        $ids = [];
                    }
                }
                fclose($source);
                if ($ids) {
                    [$done, $chunkFailed, $chunkErrors] = $this->processUniqueChunk($batch, $ids, $handle);
                    $completed += $done;
                    $failed += $chunkFailed;
                    $errors = array_merge($errors, $chunkErrors);
                }
            } else {
                $source = Storage::disk('local')->readStream($batch->source_path);
                $header = fgetcsv($source);
                $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header ?: []);
                $rowNumber = 1;
                while (($row = fgetcsv($source)) !== false) {
                    $rowNumber++;
                    $record = $this->csvRecord($header, $row);
                    try {
                        $variant = $this->assignCsvRecord($batch, $record);
                        fwrite($handle, $this->manifestLine($variant, 1));
                        $completed++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        $errors[] = ['line' => $rowNumber, 'message' => $exception->getMessage()];
                    }
                    if (($completed + $failed) % self::CHUNK_SIZE === 0) {
                        $batch->update(['completed_label_count' => $completed, 'failed_label_count' => $failed, 'error_summary' => ['count' => count($errors), 'items' => array_slice($errors, 0, 200)]]);
                        if ($this->cancelled($batch)) {
                            fclose($source);
                            fclose($handle);

                            return;
                        }
                    }
                }
                fclose($source);
            }
        } finally {
            fclose($handle);
        }

        $status = $failed > 0 ? ($completed > 0 ? 'partially_failed' : 'failed') : 'completed';
        $batch->update(['status' => $status, 'completed_label_count' => $completed, 'failed_label_count' => $failed, 'error_summary' => ['count' => count($errors), 'items' => array_slice($errors, 0, 200)], 'completed_at' => now()]);
    }

    public function labels(BarcodeBatch $batch, int $offset, int $limit): array
    {
        abort_if($batch->status !== 'completed' && $batch->status !== 'partially_failed', 409, 'Labels are not ready yet.');
        $this->ensureManifest($batch);
        $stream = Storage::disk('local')->readStream($batch->manifest_path);
        $cursor = 0;
        $result = [];
        while (($line = fgets($stream)) !== false && count($result) < $limit) {
            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }
            $copies = max(1, (int) ($record['copies'] ?? 1));
            for ($copy = 0; $copy < $copies && count($result) < $limit; $copy++) {
                if ($cursor++ < $offset) {
                    continue;
                }
                $record['copy_number'] = $copy + 1;
                unset($record['copies']);
                $result[] = $record;
            }
        }
        fclose($stream);

        return ['labels' => $result, 'offset' => $offset, 'limit' => $limit, 'total' => (int) $batch->completed_label_count];
    }

    public function csvStream(BarcodeBatch $batch): \Closure
    {
        $this->ensureManifest($batch);

        return function () use ($batch): void {
            $stream = Storage::disk('local')->readStream($batch->manifest_path);
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['variant_id', 'sku', 'product_name', 'variant', 'barcode', 'barcode_type', 'price']);
            while (($line = fgets($stream)) !== false) {
                $record = json_decode($line, true);
                if (! is_array($record)) {
                    continue;
                }
                $copies = max(1, (int) ($record['copies'] ?? 1));
                for ($copy = 0; $copy < $copies; $copy++) {
                    fputcsv($output, [$record['variant_id'] ?? '', $record['sku'] ?? '', $record['product_name'] ?? '', $record['variant'] ?? '', $record['barcode'] ?? '', $record['barcode_type'] ?? '', $record['price'] ?? '']);
                }
            }
            fclose($stream);
            fclose($output);
        };
    }

    private function processUniqueChunk(BarcodeBatch $batch, array $items, $handle): array
    {
        $done = 0;
        $failed = 0;
        $errors = [];
        foreach ($items as [$uuid, $sequence]) {
            try {
                $variant = DB::transaction(function () use ($batch, $uuid, $sequence): ProductVariant {
                    $variant = ProductVariant::query()->where('tenant_id', $batch->tenant_id)->where('uuid', $uuid)->lockForUpdate()->with('product')->firstOrFail();
                    if (! $variant->barcode) {
                        $prefix = trim((string) ($batch->options['prefix'] ?? ''));
                        $barcode = $prefix !== '' ? $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT) : $this->barcodes->generateInternal((int) $batch->tenant_id, $variant);
                        $barcode = $this->barcodes->validate($barcode, $batch->barcode_type);
                        $variant->update(['barcode' => $barcode, 'barcode_type' => $batch->barcode_type]);
                    }

                    return $variant->fresh('product');
                });
                fwrite($handle, $this->manifestLine($variant, 1));
                $done++;
            } catch (\Throwable $exception) {
                $failed++;
                $errors[] = ['variant_id' => $uuid, 'message' => $exception->getMessage()];
            }
        }

        return [$done, $failed, $errors];
    }

    private function assignCsvRecord(BarcodeBatch $batch, array $record): ProductVariant
    {
        $barcode = $this->barcodes->validate($record['barcode'] ?? null, $record['barcode_type'] ?? $batch->barcode_type);
        abort_unless($barcode, 422, 'Barcode is required.');
        $variant = ProductVariant::query()->where('tenant_id', $batch->tenant_id)->when($record['variant_id'] ?? null, fn ($query, $id) => $query->where('uuid', $id))->when(! ($record['variant_id'] ?? null), fn ($query) => $query->where('sku', $record['sku'] ?? ''))->lockForUpdate()->firstOrFail();
        if ($variant->barcode && $variant->barcode !== $barcode && ! ($batch->options['overwrite'] ?? false)) {
            throw new RuntimeException('The variant already has a barcode; explicit overwrite confirmation is required.');
        }
        $conflict = ProductVariant::query()->where('tenant_id', $batch->tenant_id)->where('barcode', $barcode)->where('id', '!=', $variant->getKey())->where('status', 'active')->exists();
        abort_if($conflict, 409, 'The barcode is already assigned to another active variant in this business.');
        $variant->update(['barcode' => $barcode, 'barcode_type' => $record['barcode_type'] ?? $batch->barcode_type]);

        return $variant->fresh('product');
    }

    private function validateCsvRecord(BarcodeBatch $batch, array $record, array $seen): array
    {
        try {
            $barcode = $this->barcodes->validate($record['barcode'] ?? null, $record['barcode_type'] ?? $batch->barcode_type);
            abort_unless($barcode, 422, 'Barcode is required.');
            abort_unless(($record['variant_id'] ?? '') !== '' || ($record['sku'] ?? '') !== '', 422, 'variant_id or sku is required.');
            if (isset($seen[$barcode])) {
                throw new RuntimeException('The barcode is duplicated in the CSV.');
            }
            $variant = ProductVariant::query()->where('tenant_id', $batch->tenant_id)->when($record['variant_id'] ?? null, fn ($query, $id) => $query->where('uuid', $id))->when(! ($record['variant_id'] ?? null), fn ($query) => $query->where('sku', $record['sku'] ?? ''))->first();
            abort_unless($variant, 422, 'The product variant is not owned by this business.');
            if ($variant->barcode && $variant->barcode !== $barcode && ! ($batch->options['overwrite'] ?? false)) {
                throw new RuntimeException('The variant already has a barcode; confirm overwrite first.');
            }
            $conflict = ProductVariant::query()->where('tenant_id', $batch->tenant_id)->where('barcode', $barcode)->where('id', '!=', $variant->getKey())->where('status', 'active')->exists();
            abort_if($conflict, 409, 'The barcode is already assigned to another active variant.');

            return ['barcode' => $barcode, 'error' => null];
        } catch (\Throwable $exception) {
            return ['barcode' => null, 'error' => $exception->getMessage()];
        }
    }

    private function csvRecord(array $header, array $row): array
    {
        $record = [];
        foreach ($header as $index => $key) {
            $record[$key] = trim((string) ($row[$index] ?? ''));
        }

        return $record;
    }

    private function manifestLine(ProductVariant $variant, int $copies): string
    {
        $attributes = is_array($variant->attributes) ? $variant->attributes : [];

        return json_encode([
            'variant_id' => $variant->uuid,
            'sku' => $variant->sku,
            'product_name' => $variant->product?->name,
            'variant' => $attributes['size'] ?? ($attributes['color'] ?? null),
            'barcode' => $variant->barcode,
            'barcode_type' => $variant->barcode_type,
            'price' => $variant->selling_price,
            'copies' => $copies,
        ], JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    private function sourcePath(BarcodeBatch $batch, string $filename): string
    {
        return 'barcode-batches/'.$batch->tenant_id.'/'.$batch->uuid.'/'.$filename;
    }

    private function manifestPath(BarcodeBatch $batch): string
    {
        return $this->sourcePath($batch, 'manifest.jsonl');
    }

    private function ensureSource(BarcodeBatch $batch): void
    {
        abort_if(! $batch->source_path || ! Storage::disk('local')->exists($batch->source_path), 404, 'The batch source file is unavailable.');
    }

    private function ensureManifest(BarcodeBatch $batch): void
    {
        abort_if(! $batch->manifest_path || ! Storage::disk('local')->exists($batch->manifest_path), 404, 'The batch labels are unavailable.');
    }

    private function cancelled(BarcodeBatch $batch): bool
    {
        return BarcodeBatch::whereKey($batch->id)->value('status') === 'cancelled';
    }

    private function selectionQuery(int $tenantId, array $data)
    {
        return ProductVariant::query()
            ->where('product_variants.tenant_id', $tenantId)
            ->where('product_variants.status', 'active')
            ->when(trim((string) ($data['search'] ?? '')) !== '', function ($query) use ($data): void {
                $search = trim((string) $data['search']);
                $query->where(function ($query) use ($search): void {
                    $query->where('product_variants.sku', 'ilike', '%'.$search.'%')
                        ->orWhere('product_variants.barcode', 'ilike', '%'.$search.'%')
                        ->orWhereHas('product', fn ($product) => $product->where('products.name', 'ilike', '%'.$search.'%')->orWhere('products.brand', 'ilike', '%'.$search.'%'));
                });
            })
            ->when(($data['brand'] ?? '') !== '', function ($query) use ($data): void {
                if ($data['brand'] === '__unbranded__') {
                    $query->whereHas('product', fn ($product) => $product->whereNull('products.brand')->orWhereRaw("trim(products.brand) = ''"));
                } else {
                    $query->whereHas('product', fn ($product) => $product->where('products.brand', $data['brand']));
                }
            })
            ->when(($data['category_id'] ?? '') !== '', fn ($query) => $query->whereHas('product.category', fn ($category) => $category->where('categories.uuid', $data['category_id'])))
            ->when(($data['product_status'] ?? 'active') !== '', fn ($query) => $query->whereHas('product', fn ($product) => $product->where('products.status', $data['product_status'])))
            ->when(($data['barcode_status'] ?? 'all') === 'assigned', fn ($query) => $query->whereNotNull('product_variants.barcode')->where('product_variants.barcode', '<>', ''))
            ->when(($data['barcode_status'] ?? 'all') === 'missing', fn ($query) => $query->where(fn ($barcode) => $barcode->whereNull('product_variants.barcode')->orWhere('product_variants.barcode', '')))
            ->orderBy('product_variants.id');
    }
}
