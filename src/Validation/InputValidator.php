<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

final class InputValidator
{
    /**
     * @param array<string, mixed> $source
     */
    public static function requireString(array $source, string $key, int $maxLength = 255): string
    {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value === '') {
            throw new ValidationException([$key => 'Dit veld is verplicht.']);
        }

        if (mb_strlen($value) > $maxLength) {
            throw new ValidationException([$key => sprintf('Dit veld mag maximaal %d tekens bevatten.', $maxLength)]);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function optionalString(array $source, string $key, int $maxLength = 255): string
    {
        if (!isset($source[$key])) {
            return '';
        }

        $value = trim((string) $source[$key]);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > $maxLength) {
            throw new ValidationException([$key => sprintf('Dit veld mag maximaal %d tekens bevatten.', $maxLength)]);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function requireDate(array $source, string $key): string
    {
        $value = trim((string) ($source[$key] ?? ''));
        if ($value === '') {
            throw new ValidationException([$key => 'Dit veld is verplicht.']);
        }

        $date = date_create_immutable($value);
        if (!$date) {
            throw new ValidationException([$key => 'Ongeldige datum opgegeven.']);
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function requireEmail(array $source, string $key, int $maxLength = 255): string
    {
        $value = self::requireString($source, $key, $maxLength);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException([$key => 'Ongeldig e-mailadres.']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function requirePhone(array $source, string $key, int $maxLength = 32): string
    {
        $value = self::requireString($source, $key, $maxLength);
        $sanitized = preg_replace('/[^\d+]/', '', $value);
        if ($sanitized === null || $sanitized === '') {
            throw new ValidationException([$key => 'Telefoonnummer bevat ongeldige tekens.']);
        }

        return $sanitized;
    }
}