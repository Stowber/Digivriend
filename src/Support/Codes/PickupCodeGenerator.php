<?php

declare(strict_types=1);

namespace App\Support\Codes;

use PDO;
use RuntimeException;

/**
 * Genereert unieke afhaalcodes voor ophaalbevestigingen.
 */
final class PickupCodeGenerator
{
    private const MAX_ATTEMPTS = 50;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $length = 6,
        private readonly string $alphabet = '0123456789'
    ) {
        if ($this->length < 1) {
            throw new RuntimeException('De lengte van de afhaalcode moet minimaal 1 zijn.');
        }

        if ($this->alphabet === '') {
            throw new RuntimeException('Het alfabet voor de afhaalcode mag niet leeg zijn.');
        }
    }

    /**
     * Probeert een aangeleverde code te gebruiken en genereert indien nodig een nieuwe unieke code.
     */
    public function generate(?string $preferredCode = null): string
    {
        if ($preferredCode !== null && $preferredCode !== '' && !$this->exists($preferredCode)) {
            return $preferredCode;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = $this->randomCode();
            if (!$this->exists($code)) {
                return $code;
            }
        }

        throw new RuntimeException('Kon geen unieke afhaalcode genereren. Probeer het opnieuw.');
    }

    private function randomCode(): string
    {
        $characters = str_split($this->alphabet);
        $maxIndex = count($characters) - 1;

        $code = '';
        for ($i = 0; $i < $this->length; $i++) {
            $code .= $characters[random_int(0, $maxIndex)];
        }

        return $code;
    }

    private function exists(string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM ophaalbevestigingen WHERE ophaalcode = :code LIMIT 1');
        $statement->execute(['code' => $code]);

        return (bool) $statement->fetchColumn();
    }
}