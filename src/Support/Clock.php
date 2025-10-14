<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

final class Clock
{
    private const DEFAULT_FORMAT = 'Y-m-d H:i:s';

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    public static function nowFormatted(string $format = self::DEFAULT_FORMAT): string
    {
        return self::now()->format($format);
    }
}