<?php

namespace App\Services;

use App\Contracts\TranslationProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ConfiguredTranslationProvider implements TranslationProvider
{
    public function isConfigured(): bool
    {
        return config('services.translation.driver') !== 'none'
            && filled(config('services.translation.endpoint'));
    }

    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('The translation provider is not configured.');
        }

        $request = Http::timeout((int) config('services.translation.timeout', 15))
            ->acceptJson()
            ->asJson();
        $key = config('services.translation.key');
        if (filled($key)) {
            $request = $request->withToken($key);
        }

        $response = $request->post(config('services.translation.endpoint'), [
            'text' => $text,
            'source_locale' => $sourceLocale,
            'target_locale' => $targetLocale,
            'context' => $context,
        ])->throw();

        $translated = $response->json('translation') ?? $response->json('data.translation');
        if (! is_string($translated) || trim($translated) === '') {
            throw new RuntimeException('The translation provider returned an invalid response.');
        }

        return trim($translated);
    }
}
