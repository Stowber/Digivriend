<?php

declare(strict_types=1);

namespace App\Support\Lang;

final class Translator
{
    private const AVAILABLE_LOCALES = ['nl', 'pl', 'en'];
    private const FALLBACK_LOCALE = 'nl';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    private static string $locale = self::FALLBACK_LOCALE;

    public static function setLocale(?string $locale): void
    {
        $normalized = self::normalizeLocale($locale);
        self::$locale = $normalized;
        self::loadLocale($normalized);
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    /**
     * @return array<int, string>
     */
    public static function availableLocales(): array
    {
        return self::AVAILABLE_LOCALES;
    }

    public static function languageName(string $locale): string
    {
        $key = 'language.options.' . strtolower($locale);

        return self::translate($key);
    }

    public static function translate(string $key, array $replace = []): string
    {
        $value = self::valueForLocale(self::$locale, $key);

        if ($value === null && self::$locale !== self::FALLBACK_LOCALE) {
            $value = self::valueForLocale(self::FALLBACK_LOCALE, $key);
        }

        if (!is_string($value)) {
            $value = $key;
        }

        if ($replace !== []) {
            foreach ($replace as $search => $replacement) {
                $value = str_replace(':' . $search, (string) $replacement, $value);
            }
        }

        return $value;
    }

    public static function loadLocale(string $locale): void
    {
        $normalized = self::normalizeLocale($locale);

        if (isset(self::$cache[$normalized])) {
            return;
        }

        $path = __DIR__ . '/../../../storage/lang/' . $normalized . '.php';

        if (!is_file($path)) {
            self::$cache[$normalized] = [];

            return;
        }

        $translations = require $path;

        if (!is_array($translations)) {
            $translations = [];
        }

        /** @var array<string, mixed> $translations */
        self::$cache[$normalized] = $translations;
    }

    private static function valueForLocale(string $locale, string $key): mixed
    {
        self::loadLocale($locale);

        $segments = explode('.', $key);
        $current = self::$cache[$locale] ?? [];

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    private static function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        if ($locale === '' || !in_array($locale, self::AVAILABLE_LOCALES, true)) {
            return self::FALLBACK_LOCALE;
        }

        return $locale;
    }
}