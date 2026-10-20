<?php

namespace App\Jobs;

use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Queueable;
use Throwable;

class TranslateModelFields implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        public readonly string $modelClass,
        public readonly int $modelId,
        public readonly int $tenantId,
        public readonly string $sourceLocale,
        public readonly string $targetLocale,
        public readonly array $fields,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(TranslationService $translations): void
    {
        $model = $translations->resolveModel($this->modelClass, $this->modelId, $this->tenantId);
        if (! $model || ! $translations->provider()->isConfigured()) {
            return;
        }

        foreach ($this->fields as $field) {
            $sourceValue = $model->getAttribute($field);
            if (blank($sourceValue)) {
                continue;
            }

            $row = Translation::query()->firstOrCreate([
                'tenant_id' => $this->tenantId,
                'translatable_type' => $this->modelClass,
                'translatable_id' => $this->modelId,
                'locale' => $this->targetLocale,
                'field' => $field,
            ], [
                'source_locale' => $this->sourceLocale,
                'source_value_hash' => hash('sha256', (string) $sourceValue),
                'state' => 'pending',
            ]);

            if ($row->state === 'reviewed' && filled($row->value)) {
                continue;
            }

            try {
                $translated = $translations->provider()->translate(
                    (string) $sourceValue,
                    $this->sourceLocale,
                    $this->targetLocale,
                    ['model' => $this->modelClass, 'field' => $field],
                );
                $row->update([
                    'source_locale' => $this->sourceLocale,
                    'source_value_hash' => hash('sha256', (string) $sourceValue),
                    'value' => $translated,
                    'state' => 'automatic',
                    'failure_reason' => null,
                ]);
            } catch (Throwable $exception) {
                $row->update([
                    'state' => 'failed',
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
            }
        }
    }
}
