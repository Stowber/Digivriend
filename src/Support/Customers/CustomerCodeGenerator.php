<?php

declare(strict_types=1);

namespace App\Support\Customers;

use App\Support\Clock;
use PDO;
use RuntimeException;

final class CustomerCodeGenerator
{
    private const PREFIX = 'K';

    public static function generate(PDO $pdo): string
    {
        $date = Clock::now();
        $prefix = self::PREFIX . $date->format('ym');

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
        $suffix = $nextSequence < 10000
            ? str_pad((string) $nextSequence, 4, '0', STR_PAD_LEFT)
            : (string) $nextSequence;

        $code = $prefix . $suffix;
        if ($code === '') {
            throw new RuntimeException('Failed to generate customer code.');
        }

        return $code;
    }
}