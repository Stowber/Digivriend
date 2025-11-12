<?php

declare(strict_types=1);

namespace App\Support\Customers;

use JsonException;

final class SuspiciousFlagRegistry
{
    public const BLOCK_APPOINTMENTS = 'appointments';
    public const BLOCK_INTAKES = 'intakes';
    public const BLOCK_PICKUPS = 'pickups';

    /**
     * @return array<string, array{label: string, description: string, blocks: array<int, string>}>
     */
    public static function definitions(): array
    {
        return [
            'thief' => [
                'label' => 'customers.profile.suspicious.options.thief.label',
                'description' => 'customers.profile.suspicious.options.thief.description',
                'blocks' => [
                    self::BLOCK_APPOINTMENTS,
                    self::BLOCK_INTAKES,
                    self::BLOCK_PICKUPS,
                ],
            ],
            'non_payer' => [
                'label' => 'customers.profile.suspicious.options.non_payer.label',
                'description' => 'customers.profile.suspicious.options.non_payer.description',
                'blocks' => [
                    self::BLOCK_INTAKES,
                    self::BLOCK_PICKUPS,
                ],
            ],
            'late_payment' => [
                'label' => 'customers.profile.suspicious.options.late_payment.label',
                'description' => 'customers.profile.suspicious.options.late_payment.description',
                'blocks' => [
                    self::BLOCK_APPOINTMENTS,
                ],
            ],
            'contract_breaker' => [
                'label' => 'customers.profile.suspicious.options.contract_breaker.label',
                'description' => 'customers.profile.suspicious.options.contract_breaker.description',
                'blocks' => [
                    self::BLOCK_APPOINTMENTS,
                    self::BLOCK_INTAKES,
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, description: string}>
     */
    public static function blockDefinitions(): array
    {
        return [
            self::BLOCK_APPOINTMENTS => [
                'label' => 'customers.profile.suspicious.blocks.appointments.label',
                'description' => 'customers.profile.suspicious.blocks.appointments.description',
            ],
            self::BLOCK_INTAKES => [
                'label' => 'customers.profile.suspicious.blocks.intakes.label',
                'description' => 'customers.profile.suspicious.blocks.intakes.description',
            ],
            self::BLOCK_PICKUPS => [
                'label' => 'customers.profile.suspicious.blocks.pickups.label',
                'description' => 'customers.profile.suspicious.blocks.pickups.description',
            ],
        ];
    }

    /**
     * @param array<int, string> $flags
     * @return array<int, string>
     */
    public static function normalizeFlags(array $flags): array
    {
        $valid = array_keys(self::definitions());
        $normalized = [];

        foreach ($flags as $flag) {
            $flag = trim((string) $flag);
            if ($flag === '') {
                continue;
            }

            if (in_array($flag, $valid, true) && !in_array($flag, $normalized, true)) {
                $normalized[] = $flag;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public static function decodeFlags(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $flags = array_filter(array_map('strval', $decoded));

        return self::normalizeFlags($flags);
    }

    /**
     * @param array<int, string> $flags
     * @return array<int, string>
     */
    public static function blocksForFlags(array $flags): array
    {
        $definitions = self::definitions();
        $blocks = [];

        foreach ($flags as $flag) {
            if (!isset($definitions[$flag])) {
                continue;
            }

            foreach ($definitions[$flag]['blocks'] as $block) {
                if (!in_array($block, $blocks, true)) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param array<int, string> $flags
     */
    public static function hasBlock(array $flags, string $block): bool
    {
        return in_array($block, self::blocksForFlags($flags), true);
    }

    public static function blockLabelKey(string $block): string
    {
        $definitions = self::blockDefinitions();

        return $definitions[$block]['label'] ?? $block;
    }

    public static function flagLabelKey(string $flag): string
    {
        $definitions = self::definitions();

        return $definitions[$flag]['label'] ?? $flag;
    }

    public static function flagDescriptionKey(string $flag): ?string
    {
        $definitions = self::definitions();

        return $definitions[$flag]['description'] ?? null;
    }
}