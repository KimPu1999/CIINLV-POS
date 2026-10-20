<?php

namespace Tests\Unit;

use App\Contracts\TranslationProvider;
use App\Models\Category;
use App\Services\TranslationService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TranslationServiceTest extends TestCase
{
    private function service(): TranslationService
    {
        return new TranslationService(new class implements TranslationProvider {
            public function isConfigured(): bool { return false; }
            public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string { return $text; }
        });
    }

    public function test_supported_locales_and_model_fields_are_explicit(): void
    {
        $service = $this->service();

        self::assertSame('my', $service->validateLocale('MY'));
        self::assertSame(['name', 'description'], $service->fieldsFor(Category::class));
        self::assertSame(Category::class, $service->modelClass('category'));
    }

    public function test_unknown_locale_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->validateLocale('fr');
    }
}
