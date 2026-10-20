<?php

namespace App\Contracts;

interface TranslationProvider
{
    public function isConfigured(): bool;

    public function translate(string $text, string $sourceLocale, string $targetLocale, array $context = []): string;
}
