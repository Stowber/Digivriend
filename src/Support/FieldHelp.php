<?php

declare(strict_types=1);

namespace App\Support;

final class FieldHelp
{
    /**
     * @var array<string, array<string, array<string, mixed>>>|null
     */
    private static ?array $config = null;

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function load(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $configPath = __DIR__ . '/../Config/FieldHelp.php';

        if (!is_file($configPath)) {
            self::$config = [];

            return self::$config;
        }

        /** @var array<string, array<string, array<string, mixed>>> $config */
        $config = require $configPath;

        self::$config = $config;

        return self::$config;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forForm(string $formKey): array
    {
        $config = self::load();

        return $config[$formKey] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $formKey, string $fieldKey): ?array
    {
        $form = self::forForm($formKey);

        return $form[$fieldKey] ?? null;
    }
}