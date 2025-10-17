<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;
use PDOException;
use RuntimeException;

final class HardwareProfileRepository
{
    /** @var array<string, string> */
    private const TYPES = [
        'case' => 'Obudowa',
        'psu' => 'Zasilacz',
        'motherboard' => 'Płyta główna',
        'cooler' => 'Chłodzenie',
        'gpu' => 'Karta graficzna',
        'storage' => 'Magazyn danych',
        'accessory' => 'Akcesorium',
        'other' => 'Inne',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function typeLabels(): array
    {
        return self::TYPES;
    }

    public function isValidType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public function createProfile(string $type, string $manufacturer, string $model, ?string $description = null): array
    {
        $type = strtolower(trim($type));
        if ($type === '' || !$this->isValidType($type)) {
            throw new RuntimeException('Nieprawidłowy typ profilu sprzętowego.');
        }

        $manufacturer = trim($manufacturer);
        $model = trim($model);

        if ($manufacturer === '' || $model === '') {
            throw new RuntimeException('Producent i model są wymagane.');
        }

        $existing = $this->findBySignature($type, $manufacturer, $model);
        if ($existing !== null) {
            return $existing;
        }

        $now = Clock::nowFormatted();

        $statement = $this->pdo->prepare(
            'INSERT INTO hardware_profiles (type, manufacturer, model, description, created_at, updated_at) '
            . 'VALUES (:type, :manufacturer, :model, :description, :created_at, :updated_at)'
        );

        $statement->execute([
            'type' => $type,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'description' => $this->normalizeNullableString($description),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $profile = $this->findProfile($id);
        if ($profile === null) {
            throw new RuntimeException('Nie udało się utworzyć profilu sprzętowego.');
        }

        return $profile;
    }

    public function findProfile(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM hardware_profiles WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findBySignature(string $type, string $manufacturer, string $model): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM hardware_profiles WHERE type = :type AND manufacturer = :manufacturer AND model = :model LIMIT 1'
        );
        $statement->execute([
            'type' => strtolower(trim($type)),
            'manufacturer' => trim($manufacturer),
            'model' => trim($model),
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProfiles(?string $type = null, int $limit = 200): array
    {
        $sql = 'SELECT * FROM hardware_profiles';
        $params = [];

        if ($type !== null && $type !== '' && $this->isValidType($type)) {
            $sql .= ' WHERE type = :type';
            $params['type'] = strtolower($type);
        }

        $sql .= ' ORDER BY manufacturer ASC, model ASC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function profilesForItem(int $itemId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT hp.*, wip.relation_type FROM warehouse_item_profiles wip '
            . 'INNER JOIN hardware_profiles hp ON hp.id = wip.profile_id '
            . 'WHERE wip.item_id = :item_id ORDER BY hp.type, hp.manufacturer, hp.model'
        );
        $statement->execute(['item_id' => $itemId]);

        return $statement->fetchAll() ?: [];
    }

    public function attachToItem(int $itemId, int $profileId, string $relationType = 'compatible'): void
    {
        $relationType = $this->normalizeRelationType($relationType);
        $now = Clock::nowFormatted();

        $statement = $this->pdo->prepare(
            'INSERT INTO warehouse_item_profiles (item_id, profile_id, relation_type, created_at) '
            . 'VALUES (:item_id, :profile_id, :relation_type, :created_at)'
        );

        try {
            $statement->execute([
                'item_id' => $itemId,
                'profile_id' => $profileId,
                'relation_type' => $relationType,
                'created_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if (!$this->isDuplicateException($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function leftoverItemsForProfile(int $profileId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT wi.*, wip.relation_type FROM warehouse_item_profiles wip '
            . 'INNER JOIN warehouse_items wi ON wi.id = wip.item_id '
            . 'WHERE wip.profile_id = :profile_id AND wip.relation_type IN (\'leftover\', \'compatible\') '
            . 'ORDER BY wi.updated_at DESC'
        );
        $statement->execute(['profile_id' => $profileId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProfileForItemRelation(int $itemId, string $relationType): ?array
    {
        $relationType = $this->normalizeRelationType($relationType);
        $statement = $this->pdo->prepare(
            'SELECT hp.* FROM warehouse_item_profiles wip '
            . 'INNER JOIN hardware_profiles hp ON hp.id = wip.profile_id '
            . 'WHERE wip.item_id = :item_id AND wip.relation_type = :relation_type LIMIT 1'
        );
        $statement->execute([
            'item_id' => $itemId,
            'relation_type' => $relationType,
        ]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    private function normalizeRelationType(string $relationType): string
    {
        $value = strtolower(trim($relationType));
        if ($value === '') {
            return 'compatible';
        }

        return match ($value) {
            'origin' => 'origin',
            'leftover' => 'leftover',
            default => 'compatible',
        };
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isDuplicateException(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'constraint');
    }
}