<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;

final class DeviceComponentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function forDevice(int $deviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT dc.*, replacement.component_name AS replacement_component_name
             FROM device_components dc
             LEFT JOIN device_components replacement ON replacement.id = dc.replaced_by_component_id
             WHERE dc.device_id = :device_id
             ORDER BY (dc.removed_at IS NULL) DESC, dc.installed_at DESC, dc.id DESC'
        );
        $statement->execute(['device_id' => $deviceId]);

        return $statement->fetchAll() ?: [];
    }

    public function find(int $componentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM device_components WHERE id = :id');
        $statement->execute(['id' => $componentId]);
        $component = $statement->fetch();

        return $component !== false ? $component : null;
    }

    public function add(
        int $deviceId,
        string $category,
        string $componentName,
        ?string $manufacturer,
        ?string $model,
        ?string $serialNumber,
        ?string $specifications,
        ?string $notes,
        ?string $installedAt = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO device_components (device_id, category, component_name, manufacturer, model, serial_number, specifications, notes, installed_at, created_at, updated_at)
             VALUES (:device_id, :category, :component_name, :manufacturer, :model, :serial_number, :specifications, :notes, :installed_at, :created_at, :updated_at)'
        );

        $now = Clock::nowFormatted();

        $statement->execute([
            'device_id' => $deviceId,
            'category' => $category,
            'component_name' => $componentName,
            'manufacturer' => $manufacturer ?: null,
            'model' => $model ?: null,
            'serial_number' => $serialNumber ?: null,
            'specifications' => $specifications ?: null,
            'notes' => $notes ?: null,
            'installed_at' => $installedAt ?: $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function retire(int $componentId, ?string $removalReason = null, ?int $replacementComponentId = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE device_components
             SET removed_at = :removed_at,
                 removal_reason = :removal_reason,
                 replaced_by_component_id = :replacement_component_id,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $now = Clock::nowFormatted();

        $statement->execute([
            'id' => $componentId,
            'removed_at' => $now,
            'removal_reason' => $removalReason ?: null,
            'replacement_component_id' => $replacementComponentId,
            'updated_at' => $now,
        ]);
    }
}