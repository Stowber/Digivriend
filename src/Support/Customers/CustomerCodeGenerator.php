<?php

declare(strict_types=1);

namespace App\Support\Customers;

use App\Support\Clock;
use PDO;
use RuntimeException;

final class CustomerCodeGenerator
{
    private const PRIVATE_PREFIX = 'K';
    private const BUSINESS_PREFIX = 'B';

    public static function generate(PDO $pdo, string $type = 'private'): string
    {
        $normalizedType = $type === 'business' ? 'business' : 'private';

        $date = Clock::now();
        $prefixLetter = $normalizedType === 'business' ? self::BUSINESS_PREFIX : self::PRIVATE_PREFIX;
        $prefix = $prefixLetter . $date->format('ym');

        $statement = $pdo->prepare(<<<SQL
            SELECT customer_code
            FROM customers
            WHERE customer_code LIKE :prefix
            ORDER BY id DESC
            LIMIT 100
        SQL);
        $statement->execute([
            'prefix' => $prefix . '%',
        ]);
        $rows = $statement->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $maxSequence = -1;
        foreach ($rows as $code) {
            if (!is_string($code)) {
                continue;
            }

            if (!str_starts_with($code, $prefix)) {
                continue;
            }

            $numericPart = substr($code, strlen($prefix));
            if ($numericPart === false || $numericPart === '') {
                continue;
            }

            if (!ctype_digit($numericPart)) {
                continue;
            }

            $sequence = (int) $numericPart;
            if ($sequence > $maxSequence) {
                $maxSequence = $sequence;
            }
        }

        $nextSequence = max(0, $maxSequence + 1);
        $maxDigits = $normalizedType === 'business' ? 3 : 4;
        $threshold = 10 ** $maxDigits;
        $suffix = $nextSequence < $threshold
            ? str_pad((string) $nextSequence, $maxDigits, '0', STR_PAD_LEFT)
            : (string) $nextSequence;

        $code = $prefix . $suffix;
        if ($code === '') {
            throw new RuntimeException('Failed to generate customer code.');
        }

        return $code;
    }
}