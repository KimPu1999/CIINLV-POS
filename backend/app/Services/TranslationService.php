<?php

namespace App\Services;

use App\Contracts\TranslationProvider;
use App\Jobs\TranslateModelFields;
use App\Models\Category;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class TranslationService
{
    public const SUPPORTED_LOCALES = ['en', 'my'];

    public const DEFAULT_LOCALE = 'en';

    /** @var array<class-string<Model>, list<string>> */
    private const TRANSLATABLE_FIELDS = [
        Product::class => ['name', 'description', 'brand'],
        Category::class => ['name', 'description'],
        Supplier::class => ['name', 'notes'],
        Tenant::class => ['display_name', 'description', 'business_type'],
        InventoryLocation::class => ['name', 'description'],
    ];

    /** @var array<string, mixed> */
    private array $valueCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $statusCache = [];

    private ?bool $storageAvailable = null;

    public function __construct(private readonly TranslationProvider $provider) {}

    public function locale(Request|string|null $requestOrLocale = null): string
    {
        $locale = $requestOrLocale instanceof Request
            ? ($requestOrLocale->input('lang') ?: $requestOrLocale->input('locale'))
            : $requestOrLocale;

        return $this->validateLocale($locale ?: app()->getLocale());
    }

    public function validateLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) ($locale ?: self::DEFAULT_LOCALE)));
        $baseLocale = preg_split('/[-_]/', $locale, 2)[0] ?: $locale;

        if (! in_array($baseLocale, self::SUPPORTED_LOCALES, true)) {
            throw ValidationException::withMessages([
                'lang' => ['Supported locales are en and my.'],
            ]);
        }

        return $baseLocale;
    }

    public function fieldsFor(Model|string $model): array
    {
        $class = is_string($model) ? $model : $model::class;

        return self::TRANSLATABLE_FIELDS[$class] ?? [];
    }

    public function modelClass(string $type): string
    {
        $normalized = strtolower(trim($type));
        $map = [
            'product' => Product::class,
            'category' => Category::class,
            'supplier' => Supplier::class,
            'tenant' => Tenant::class,
            'business' => Tenant::class,
            'inventory_location' => InventoryLocation::class,
        ];

        if (! isset($map[$normalized])) {
            throw ValidationException::withMessages(['model_type' => ['This record type does not support translations.']]);
        }

        return $map[$normalized];
    }

    public function resolveModel(string $class, int|string $id, int $tenantId): ?Model
    {
        if (! array_key_exists($class, self::TRANSLATABLE_FIELDS)) {
            return null;
        }

        return $class::query()
            ->whereKey($id)
            ->when($class !== Tenant::class, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    public function tenantId(Model $model): int
    {
        return (int) ($model->tenant_id ?: $model->getKey());
    }

    public function localizedValue(Model $model, string $field, string $locale): mixed
    {
        $locale = $this->validateLocale($locale);
        if (! in_array($field, $this->fieldsFor($model), true)) {
            return $model->getAttribute($field);
        }

        $source = $model->getAttribute($field);
        if (! $this->hasStorage()) {
            return $source;
        }
        if ($locale === self::DEFAULT_LOCALE && blank($source)) {
            return $source;
        }

        $cacheKey = $this->tenantId($model).':'.$model::class.':'.$model->getKey().':'.$locale.':'.$field;
        if (array_key_exists($cacheKey, $this->valueCache)) {
            return $this->valueCache[$cacheKey];
        }

        $translation = Translation::query()
            ->where('tenant_id', $this->tenantId($model))
            ->where('translatable_type', $model::class)
            ->where('translatable_id', $model->getKey())
            ->where('locale', $locale)
            ->where('field', $field)
            ->whereNotNull('value')
            ->value('value');

        return $this->valueCache[$cacheKey] = filled($translation) ? $translation : $source;
    }

    public function translationStatus(Model $model, string $locale): array
    {
        $locale = $this->validateLocale($locale);
        if (! $this->hasStorage()) {
            return collect($this->fieldsFor($model))->mapWithKeys(fn (string $field): array => [$field => [
                'state' => 'pending',
                'has_value' => false,
                'failure_reason' => null,
            ]])->all();
        }
        $cacheKey = $this->tenantId($model).':'.$model::class.':'.$model->getKey().':'.$locale;
        if (isset($this->statusCache[$cacheKey])) {
            return $this->statusCache[$cacheKey];
        }

        $rows = Translation::query()
            ->where('tenant_id', $this->tenantId($model))
            ->where('translatable_type', $model::class)
            ->where('translatable_id', $model->getKey())
            ->where('locale', $locale)
            ->get(['field', 'state', 'value', 'failure_reason']);

        return $this->statusCache[$cacheKey] = collect($this->fieldsFor($model))->mapWithKeys(function (string $field) use ($rows): array {
            $row = $rows->firstWhere('field', $field);

            return [$field => [
                'state' => $row?->state ?? 'pending',
                'has_value' => filled($row?->value),
                'failure_reason' => $row?->failure_reason,
            ]];
        })->all();
    }

    public function ensurePending(Model $model, string $sourceLocale = self::DEFAULT_LOCALE): void
    {
        if (! $this->hasStorage()) {
            return;
        }
        $sourceLocale = $this->validateLocale($sourceLocale);
        $tenantId = $this->tenantId($model);
        foreach ($this->fieldsFor($model) as $field) {
            $sourceValue = $model->getAttribute($field);
            $hash = filled($sourceValue) ? hash('sha256', (string) $sourceValue) : null;

            Translation::updateOrCreate(
                ['tenant_id' => $tenantId, 'translatable_type' => $model::class, 'translatable_id' => $model->getKey(), 'locale' => $sourceLocale, 'field' => $field],
                ['source_locale' => $sourceLocale, 'value' => $sourceValue, 'source_value_hash' => $hash, 'state' => 'reviewed', 'reviewed_at' => now(), 'failure_reason' => null]
            );

            $targetLocale = $sourceLocale === 'en' ? 'my' : 'en';
            $target = Translation::query()->firstOrNew([
                'tenant_id' => $tenantId,
                'translatable_type' => $model::class,
                'translatable_id' => $model->getKey(),
                'locale' => $targetLocale,
                'field' => $field,
            ]);
            $isReviewed = $target->exists && $target->state === 'reviewed';
            $sourceChanged = $target->source_value_hash !== $hash;
            if (! $target->exists || ($sourceChanged && ! $isReviewed)) {
                $target->fill([
                    'source_locale' => $sourceLocale,
                    'value' => $sourceChanged ? null : $target->value,
                    'source_value_hash' => $hash,
                    'state' => $isReviewed ? 'reviewed' : 'pending',
                    'failure_reason' => null,
                    'reviewed_at' => $isReviewed ? $target->reviewed_at : null,
                ])->save();
            }
        }
    }

    public function queueForModel(Model $model, ?string $sourceLocale = null): void
    {
        $sourceLocale = $this->validateLocale($sourceLocale ?: self::DEFAULT_LOCALE);
        $this->ensurePending($model, $sourceLocale);
        $targetLocale = $sourceLocale === 'en' ? 'my' : 'en';

        TranslateModelFields::dispatch(
            $model::class,
            (int) $model->getKey(),
            $this->tenantId($model),
            $sourceLocale,
            $targetLocale,
            $this->fieldsFor($model),
        )->afterCommit();
    }

    public function provider(): TranslationProvider
    {
        return $this->provider;
    }

    /**
     * Translation storage is additive. A deployment can therefore continue
     * serving canonical product/category/business values while the migration
     * is still pending, instead of turning every localized request into a
     * 42P01 relation-not-found error.
     */
    public function hasStorage(): bool
    {
        if ($this->storageAvailable !== null) {
            return $this->storageAvailable;
        }

        try {
            return $this->storageAvailable = Schema::hasTable('translations');
        } catch (Throwable) {
            return $this->storageAvailable = false;
        }
    }

    public function updateManualTranslation(Translation $translation, string $value, int $userId): Translation
    {
        $translation->update([
            'value' => trim($value),
            'state' => 'reviewed',
            'translated_by_user_id' => $userId,
            'reviewed_at' => now(),
            'failure_reason' => null,
        ]);

        return $translation->refresh();
    }
}
