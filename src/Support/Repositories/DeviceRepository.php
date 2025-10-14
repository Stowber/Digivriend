<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class DeviceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findOrCreate(
        int $customerId,
        ?string $brand,
        ?string $model,
        ?string $serialNumber = null
    ): ?array {
        $existing = $this->findExisting($customerId, $brand, $model, $serialNumber);

        if ($existing !== null) {
            return $existing;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO devices (customer_id, brand, model, serial_number) VALUES (:customer_id, :brand, :model, :serial_number)'
        );
        $statement->execute([
            'customer_id' => $customerId,
            'brand' => $brand ?: null,
            'model' => $model ?: null,
            'serial_number' => $serialNumber ?: null,
        ]);

        return $this->findExisting($customerId, $brand, $model, $serialNumber);
    }

    private function findExisting(
        int $customerId,
        ?string $brand,
        ?string $model,
        ?string $serialNumber
    ): ?array {
        if ($serialNumber) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM devices WHERE customer_id = :customer_id AND serial_number = :serial LIMIT 1'
            );
            $statement->execute([
                'customer_id' => $customerId,
                'serial' => $serialNumber,
            ]);
            $device = $statement->fetch();
            if ($device !== false) {
                return $device;
            }
        }

        if ($brand || $model) {
            $statement = $this->pdo->prepare(
                'SELECT * FROM devices WHERE customer_id = :customer_id AND brand = :brand AND model = :model LIMIT 1'
            );
            $statement->execute([
                'customer_id' => $customerId,
                'brand' => $brand ?: null,
                'model' => $model ?: null,
            ]);
            $device = $statement->fetch();
            if ($device !== false) {
                return $device;
            }
        }

        return null;
    }
}