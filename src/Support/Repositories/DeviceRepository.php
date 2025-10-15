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
        ?string $serialNumber = null,
        ?string $deviceType = null,
        ?string $notes = null
    ): ?array {
        $existing = $this->findExisting($customerId, $brand, $model, $serialNumber);

        if ($existing !== null) {
            $deviceId = (int) $existing['id'];
            $this->ensureBarcode($deviceId);
            $this->updateDeviceMetadata($deviceId, $deviceType, $notes, false);

            return $this->findById($deviceId);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO devices (customer_id, brand, model, serial_number, device_type, notes)
             VALUES (:customer_id, :brand, :model, :serial_number, :device_type, :notes)'
        );
        $statement->execute([
            'customer_id' => $customerId,
            'brand' => $brand ?: null,
            'model' => $model ?: null,
            'serial_number' => $serialNumber ?: null,
            'device_type' => $deviceType ?: null,
            'notes' => $notes ?: null,
        ]);

        $deviceId = (int) $this->pdo->lastInsertId();
        $this->ensureBarcode($deviceId);

        return $this->findById($deviceId);
    }

    public function updateDeviceProfile(
        int $deviceId,
        ?string $brand,
        ?string $model,
        ?string $serialNumber,
        ?string $deviceType,
        ?string $notes
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE devices
             SET brand = :brand,
                 model = :model,
                 serial_number = :serial_number,
                 device_type = :device_type,
                 notes = :notes,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $deviceId,
            'brand' => $brand ?: null,
            'model' => $model ?: null,
            'serial_number' => $serialNumber ?: null,
            'device_type' => $deviceType ?: null,
            'notes' => $notes ?: null,
        ]);
    }

    public function updateDeviceMetadata(int $deviceId, ?string $deviceType, ?string $notes, bool $touchTimestamp = true): void
    {
        $fields = [];
        $params = ['id' => $deviceId];

        if ($deviceType !== null) {
            $fields[] = 'device_type = :device_type';
            $params['device_type'] = $deviceType !== '' ? $deviceType : null;
        }

        if ($notes !== null) {
            $fields[] = 'notes = :notes';
            $params['notes'] = $notes !== '' ? $notes : null;
        }

        if ($fields === []) {
            return;
        }

        if ($touchTimestamp) {
            $fields[] = 'updated_at = CURRENT_TIMESTAMP';
        }

        $sql = 'UPDATE devices SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    public function findById(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM devices WHERE id = :id');
        $statement->execute(['id' => $deviceId]);
        $device = $statement->fetch();

        return $device !== false ? $device : null;
    }

    public function findByBarcode(string $barcode): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM devices WHERE barcode = :barcode');
        $statement->execute(['barcode' => $barcode]);
        $device = $statement->fetch();

        return $device !== false ? $device : null;
    }

    public function findWithCustomer(int $deviceId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.*, c.full_name, c.email, c.phone, c.address, c.postal_code, c.city
             FROM devices d
             INNER JOIN customers c ON c.id = d.customer_id
             WHERE d.id = :id'
        );
        $statement->execute(['id' => $deviceId]);
        $record = $statement->fetch();

        return $record !== false ? $record : null;
    }

    public function findWithCustomerByBarcode(string $barcode): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.*, c.full_name, c.email, c.phone, c.address, c.postal_code, c.city
             FROM devices d
             INNER JOIN customers c ON c.id = d.customer_id
             WHERE d.barcode = :barcode'
        );
        $statement->execute(['barcode' => $barcode]);
        $record = $statement->fetch();

        return $record !== false ? $record : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentDevices(int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.*, c.full_name
             FROM devices d
             INNER JOIN customers c ON c.id = d.customer_id
             ORDER BY d.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    public function ensureBarcode(int $deviceId): string
    {
        $device = $this->findById($deviceId);

        if ($device === null) {
            return '';
        }

        $current = (string) ($device['barcode'] ?? '');
        if ($current !== '') {
            return $current;
        }

        do {
            $candidate = $this->generateDeviceBarcodeValue();
        } while ($this->barcodeExists($candidate));

        $statement = $this->pdo->prepare('UPDATE devices SET barcode = :barcode WHERE id = :id');
        $statement->execute([
            'id' => $deviceId,
            'barcode' => $candidate,
        ]);

        return $candidate;
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

    private function barcodeExists(string $barcode): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM devices WHERE barcode = :barcode');
        $statement->execute(['barcode' => $barcode]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function generateDeviceBarcodeValue(): string
    {
        $datePart = (new \DateTimeImmutable())->format('ymd');
        $random = strtoupper(bin2hex(random_bytes(3)));

        return 'DV' . $datePart . $random;
    }
}