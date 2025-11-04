<?php

declare(strict_types=1);

use App\Support\Lang\Translator;

if (!function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return Translator::translate($key, $replace);
    }
}

if (!function_exists('available_locales')) {
    /**
     * @return array<int, string>
     */
    function available_locales(): array
    {
        return Translator::availableLocales();
    }
}

if (!function_exists('language_name')) {
    function language_name(string $locale): string
    {
        return Translator::languageName($locale);
    }
}