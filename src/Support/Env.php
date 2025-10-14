<?php

declare(strict_types=1);

namespace App\Support;

final class Env
{
    private const SUPPORTED_BOOLEAN = ['true', 'false', '1', '0', 'yes', 'no'];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $line = self::stripExportPrefix($line);

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $key = self::stripBom(trim($key));
            $value = self::stripBom(trim($value));

            if ($key === '') {
                continue;
            }

            if ($value !== '' && ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
            putenv(sprintf('%s=%s', $key, $value));
        }
    }

    private static function stripExportPrefix(string $line): string
    {
        if (str_starts_with($line, 'export ')) {
            return ltrim(substr($line, 7));
        }

        if (str_starts_with($line, 'set ')) {
            return ltrim(substr($line, 4));
        }

        return $line;
    }

    private static function stripBom(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return str_starts_with($value, "\u{FEFF}") ? substr($value, 1) : $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        $trimmed = is_string($value) ? trim($value) : $value;

        if (is_string($trimmed) && in_array(strtolower($trimmed), self::SUPPORTED_BOOLEAN, true)) {
            return filter_var($trimmed, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        }

        if ($trimmed === '') {
            return $default;
        }

        return $trimmed;
    }
}