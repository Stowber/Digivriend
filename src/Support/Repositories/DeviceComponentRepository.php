<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;

final class DeviceComponentRepository
{
    /**
     * @var array<int, string>|null
     */
    private ?array $columnCache = null;
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

    public function distinctValues(int $limit = 20): array
    {
        $limit = max(1, $limit);

        $columns = [
            'component_name',
            'manufacturer',
            'model',
            'supplier',
            'inventory_location',
        ];

        $values = [];

        foreach ($columns as $column) {
            if (!$this->hasColumn($column)) {
                $values[$column] = [];

                continue;
            }
            $statement = $this->pdo->prepare(
                "SELECT DISTINCT {$column} FROM device_components WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} ASC LIMIT :limit"
            );
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();

            $columnValues = [];
            while (($value = $statement->fetchColumn()) !== false) {
                $columnValues[] = (string) $value;
            }

            $values[$column] = $columnValues;
        }

        return $values;
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
        ?string $installedAt = null,
        ?string $assetTag = null,
        ?string $supplier = null,
        ?string $purchaseReference = null,
        ?string $purchaseCost = null,
        ?string $inventoryLocation = null,
        ?string $conditionStatus = null,
        ?string $warrantyExpiresAt = null,
        ?int $maintenanceIntervalDays = null,
        ?string $lastAuditedAt = null
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO device_components (device_id, category, component_name, manufacturer, model, serial_number, specifications, notes, installed_at, asset_tag, supplier, purchase_reference, purchase_cost, inventory_location, condition_status, warranty_expires_at, maintenance_interval_days, last_audited_at, created_at, updated_at)
             VALUES (:device_id, :category, :component_name, :manufacturer, :model, :serial_number, :specifications, :notes, :installed_at, :asset_tag, :supplier, :purchase_reference, :purchase_cost, :inventory_location, :condition_status, :warranty_expires_at, :maintenance_interval_days, :last_audited_at, :created_at, :updated_at)'
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
            'asset_tag' => $assetTag ?: null,
            'supplier' => $supplier ?: null,
            'purchase_reference' => $purchaseReference ?: null,
            'purchase_cost' => $purchaseCost ?: null,
            'inventory_location' => $inventoryLocation ?: null,
            'condition_status' => $conditionStatus ?: null,
            'warranty_expires_at' => $warrantyExpiresAt ?: null,
            'maintenance_interval_days' => $maintenanceIntervalDays,
            'last_audited_at' => $lastAuditedAt ?: null,
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
    private function hasColumn(string $column): bool
    {
        $availableColumns = $this->availableColumns();

        return in_array(strtolower($column), $availableColumns, true);
    }

    /**
     * @return array<int, string>
     */
    private function availableColumns(): array
    {
        if ($this->columnCache !== null) {
            return $this->columnCache;
        }

        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        $columns = [];
        [$sql, $key] = match ($driver) {
            'sqlite' => ['PRAGMA table_info(device_components)', 'name'],
            'pgsql' => [
                "SELECT column_name FROM information_schema.columns WHERE table_name = 'device_components' AND table_schema = current_schema()",
                'column_name',
            ],
            default => ['SHOW COLUMNS FROM device_components', 'Field'],
        };

        $statement = $this->pdo->query($sql);

        if ($statement === false) {
            return $this->columnCache = [];
        }

        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $name = $row[$key] ?? $row['column_name'] ?? $row['name'] ?? $row['field'] ?? null;

            if (!is_string($name) || $name === '') {
                continue;
            }

            $columns[] = strtolower($name);
        }

        return $this->columnCache = $columns;
    }
}