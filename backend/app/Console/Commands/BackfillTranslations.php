<?php

namespace App\Console\Commands;

use App\Jobs\TranslateModelFields;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Services\TranslationService;
use Illuminate\Console\Command;

class BackfillTranslations extends Command
{
    protected $signature = 'translations:backfill
        {--tenant= : Limit the backfill to a tenant ID}
        {--locale=en : Source locale of existing values (en or my)}
        {--run-now : Execute translation jobs in this process instead of queueing them}';

    protected $description = 'Create safe, repeatable translation records for existing translatable business data';

    public function handle(TranslationService $translations): int
    {
        $sourceLocale = $translations->validateLocale($this->option('locale'));
        $tenantId = $this->option('tenant');
        if ($tenantId !== null && ! ctype_digit((string) $tenantId)) {
            $this->error('--tenant must be a numeric tenant ID.');

            return self::INVALID;
        }
        $models = [Product::class, Category::class, Supplier::class, InventoryLocation::class, Tenant::class];
        $queued = 0;
        $failed = 0;

        foreach ($models as $class) {
            $query = $class::query();
            if ($tenantId !== null) {
                if ($class === Tenant::class) {
                    $query->whereKey($tenantId);
                } else {
                    $query->where('tenant_id', $tenantId);
                }
            }

            $query->chunkById(100, function ($records) use ($translations, $sourceLocale, &$queued, &$failed): void {
                foreach ($records as $model) {
                    try {
                        $translations->ensurePending($model, $sourceLocale);
                        $targetLocale = $sourceLocale === 'en' ? 'my' : 'en';
                        $job = new TranslateModelFields($model::class, (int) $model->getKey(), $translations->tenantId($model), $sourceLocale, $targetLocale, $translations->fieldsFor($model));
                        if ($this->option('run-now')) {
                            $job->handle($translations);
                        } else {
                            dispatch($job)->afterCommit();
                        }
                        $queued++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        $this->warn($model::class.'#'.$model->getKey().': '.$exception->getMessage());
                    }
                }
            });
        }

        $this->info("Processed {$queued} records; {$failed} failed. Missing provider translations remain pending.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
