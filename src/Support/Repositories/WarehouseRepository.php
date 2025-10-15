<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Barcode\BarcodeService;
use App\Support\Clock;
use PDO;
use PDOException;
use RuntimeException;

final class WarehouseRepository
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'expected' => 'Oczekiwane',
        'received' => 'Przyjęte',
        'reserved' => 'Zarezerwowane',
        'in_service' => 'W naprawie',
        'ready' => 'Gotowe do wydania',
        'completed' => 'Wydane klientowi',
    ];

    /** @var array<string, string> */
    private const MOVEMENT_LABELS = [
        'registered' => 'Rejestracja',
        'inbound' => 'Przyjęcie',
        'reserve' => 'Rezerwacja',
        'release' => 'Zwolnienie',
        'outbound' => 'Wydanie',
        'adjustment' => 'Korekta',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    /**
     * @return array<string, string>
     */
    public function movementLabels(): array
    {
        return self::MOVEMENT_LABELS;
    }

    public function isValidStatus(string $status): bool
    {
        return isset(self::STATUS_LABELS[$status]);
    }

    public function isValidMovementType(string $movementType): bool
    {
        return isset(self::MOVEMENT_LABELS[$movementType]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listItems(?string $status = null, ?string $search = null, int $limit = 100): array
    {
        $sql = <<<SQL
            SELECT
                wi.*, 
                c.reference_code AS case_reference_code,
                c.type AS case_type,
                c.status AS case_status,
                cust.full_name AS customer_name
            FROM warehouse_items wi
            LEFT JOIN cases c ON c.id = wi.case_id
            LEFT JOIN customers cust ON cust.id = c.customer_id
        SQL;
        $conditions = [];
        $params = [];

        if ($status !== null && $status !== '' && $this->isValidStatus($status)) {
            $conditions[] = 'wi.status = :status';
            $params['status'] = $status;
        }

        if ($search !== null && trim($search) !== '') {
            $like = '%' . trim($search) . '%';
            $conditions[] = '(wi.name LIKE :search OR wi.reference_code LIKE :search OR wi.barcode LIKE :search OR c.reference_code LIKE :search)';
            $params['search'] = $like;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY wi.updated_at DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $statement = $this->pdo->query('SELECT status, COUNT(*) AS total FROM warehouse_items GROUP BY status');
        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);

        if ($statement !== false) {
            foreach ($statement->fetchAll() ?: [] as $row) {
                $status = (string) ($row['status'] ?? '');
                if ($status === '' || !isset($counts[$status])) {
                    continue;
                }
                $counts[$status] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function totalItems(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM warehouse_items');
        $value = $statement !== false ? $statement->fetchColumn() : 0;

        return (int) ($value ?: 0);
    }

    public function totalQuantity(): int
    {
        $statement = $this->pdo->query('SELECT SUM(quantity) FROM warehouse_items');
        $value = $statement !== false ? $statement->fetchColumn() : 0;

        return (int) ($value ?: 0);
    }

    public function totalReserved(): int
    {
        $statement = $this->pdo->query('SELECT SUM(reserved_quantity) FROM warehouse_items');
        $value = $statement !== false ? $statement->fetchColumn() : 0;

        return (int) ($value ?: 0);
    }

    public function createItem(
        string $name,
        int $quantity,
        ?string $category,
        ?string $location,
        ?int $caseId,
        ?string $notes,
        string $status,
        ?string $referenceCode = null,
        ?string $performedBy = null
    ): array {
        if (!$this->isValidStatus($status)) {
            $status = 'received';
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Naam van magazynowego elementu nie może być pusta.');
        }

        $quantity = max(0, $quantity);
        $reference = $this->normalizeReferenceCode($referenceCode);
        $barcode = $this->generateBarcodeValue($reference);
        $now = Clock::nowFormatted();
        $receivedAt = $status === 'received' ? $now : null;
        $reservedAt = $status === 'reserved' ? $now : null;
        $readyAt = $status === 'ready' ? $now : null;
        $completedAt = $status === 'completed' ? $now : null;

        $statement = $this->pdo->prepare(
            'INSERT INTO warehouse_items (reference_code, name, category, location, status, quantity, reserved_quantity, case_id, device_id, barcode, notes, received_at, reserved_at, ready_at, completed_at, last_movement_at, created_at, updated_at)
             VALUES (:reference_code, :name, :category, :location, :status, :quantity, 0, :case_id, NULL, :barcode, :notes, :received_at, :reserved_at, :ready_at, :completed_at, :last_movement_at, :created_at, :updated_at)'
        );

        $statement->execute([
            'reference_code' => $reference,
            'name' => $name,
            'category' => $this->normalizeNullableString($category),
            'location' => $this->normalizeNullableString($location),
            'status' => $status,
            'quantity' => $quantity,
            'case_id' => $caseId,
            'barcode' => $barcode,
            'notes' => $this->normalizeNullableString($notes),
            'received_at' => $receivedAt,
            'reserved_at' => $reservedAt,
            'ready_at' => $readyAt,
            'completed_at' => $completedAt,
            'last_movement_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $itemId = (int) $this->pdo->lastInsertId();

        $this->recordMovement($itemId, 'registered', $quantity, $caseId, 'Automatyczne zarejestrowanie pozycji', $performedBy);

        $item = $this->findItem($itemId);
        if ($item === null) {
            throw new RuntimeException('Magazynowy element nie został zapisany.');
        }

        return $item;
    }

    public function updateStatus(int $itemId, string $status, ?int $caseId, ?string $location, ?string $notes): bool
    {
        if (!$this->isValidStatus($status)) {
            return false;
        }

        $now = Clock::nowFormatted();
        $setParts = [
            'status = :status',
            'case_id = :case_id',
            'location = :location',
            'notes = :notes',
            'updated_at = :updated_at',
            'last_movement_at = COALESCE(last_movement_at, :last_movement_at)'
        ];
        $params = [
            'status' => $status,
            'case_id' => $caseId,
            'location' => $this->normalizeNullableString($location),
            'notes' => $this->normalizeNullableString($notes),
            'updated_at' => $now,
            'last_movement_at' => $now,
            'id' => $itemId,
        ];

        if ($status === 'received') {
            $setParts[] = 'received_at = COALESCE(received_at, :received_at)';
            $params['received_at'] = $now;
        }

        if ($status === 'reserved') {
            $setParts[] = 'reserved_at = :reserved_at';
            $params['reserved_at'] = $now;
        }

        if ($status === 'ready') {
            $setParts[] = 'ready_at = :ready_at';
            $params['ready_at'] = $now;
        }

        if ($status === 'completed') {
            $setParts[] = 'completed_at = :completed_at';
            $params['completed_at'] = $now;
        }

        $sql = 'UPDATE warehouse_items SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $statement = $this->pdo->prepare($sql);

        return $statement->execute($params);
    }

    public function recordMovement(
        int $itemId,
        string $movementType,
        int $quantity,
        ?int $caseId,
        ?string $notes,
        ?string $performedBy
    ): bool {
        if (!$this->isValidMovementType($movementType)) {
            return false;
        }

        $item = $this->findRawItem($itemId);
        if ($item === null) {
            return false;
        }

        $quantityValue = $movementType === 'adjustment' ? $quantity : abs($quantity);
        if ($movementType !== 'adjustment' && $movementType !== 'registered' && $quantityValue <= 0) {
            return false;
        }

        $now = Clock::nowFormatted();
        $caseReference = $caseId ?: null;

        try {
            $this->pdo->beginTransaction();

            $movementStatement = $this->pdo->prepare(
                'INSERT INTO warehouse_movements (item_id, case_id, movement_type, quantity, notes, performed_by, created_at)
                 VALUES (:item_id, :case_id, :movement_type, :quantity, :notes, :performed_by, :created_at)'
            );
            $movementStatement->execute([
                'item_id' => $itemId,
                'case_id' => $caseReference,
                'movement_type' => $movementType,
                'quantity' => $quantityValue,
                'notes' => $this->normalizeNullableString($notes),
                'performed_by' => $this->normalizeNullableString($performedBy),
                'created_at' => $now,
            ]);

            $quantityDelta = $this->quantityDelta($movementType, $quantityValue);
            $reservedDelta = $this->reservedDelta($movementType, $quantityValue);

            $newQuantity = max(0, (int) $item['quantity'] + $quantityDelta);
            $newReserved = max(0, (int) $item['reserved_quantity'] + $reservedDelta);

            $updateSql = 'UPDATE warehouse_items SET quantity = :quantity, reserved_quantity = :reserved_quantity, last_movement_at = :last_movement_at, updated_at = :updated_at';
            $updateParams = [
                'quantity' => $newQuantity,
                'reserved_quantity' => $newReserved,
                'last_movement_at' => $now,
                'updated_at' => $now,
                'id' => $itemId,
            ];

            if ($caseReference !== null) {
                $updateSql .= ', case_id = :case_id';
                $updateParams['case_id'] = $caseReference;
            }

            $updateSql .= ' WHERE id = :id';

            $updateStatement = $this->pdo->prepare($updateSql);
            $updateStatement->execute($updateParams);

            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return true;
    }

    public function findItem(int $itemId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT wi.*, c.reference_code AS case_reference_code, c.type AS case_type, c.status AS case_status, cust.full_name AS customer_name
             FROM warehouse_items wi
             LEFT JOIN cases c ON c.id = wi.case_id
             LEFT JOIN customers cust ON cust.id = c.customer_id
             WHERE wi.id = :id'
        );
        $statement->execute(['id' => $itemId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function findByReference(string $referenceCode): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT wi.*, c.reference_code AS case_reference_code, c.type AS case_type, c.status AS case_status, cust.full_name AS customer_name
             FROM warehouse_items wi
             LEFT JOIN cases c ON c.id = wi.case_id
             LEFT JOIN customers cust ON cust.id = c.customer_id
             WHERE wi.reference_code = :reference LIMIT 1'
        );
        $statement->execute(['reference' => $referenceCode]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function ensureBarcode(int $itemId): string
    {
        $item = $this->findRawItem($itemId);
        if ($item === null) {
            throw new RuntimeException('Magazynowy element nie istnieje.');
        }

        $existingBarcode = trim((string) ($item['barcode'] ?? ''));
        if ($existingBarcode !== '') {
            return $existingBarcode;
        }

        $reference = (string) ($item['reference_code'] ?? '');
        $barcode = $this->generateBarcodeValue($reference !== '' ? $reference : null);

        $statement = $this->pdo->prepare('UPDATE warehouse_items SET barcode = :barcode WHERE id = :id');
        $statement->execute([
            'barcode' => $barcode,
            'id' => $itemId,
        ]);

        return $barcode;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByCaseId(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT wi.*, c.reference_code AS case_reference_code, c.type AS case_type, c.status AS case_status, cust.full_name AS customer_name
             FROM warehouse_items wi
             LEFT JOIN cases c ON c.id = wi.case_id
             LEFT JOIN customers cust ON cust.id = c.customer_id
             WHERE wi.case_id = :case_id
             ORDER BY wi.updated_at DESC'
        );
        $statement->execute(['case_id' => $caseId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array{id:int, name:string, barcode:?string, status:string}>
     */
    public function itemOptions(int $limit = 120): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, barcode, status FROM warehouse_items ORDER BY updated_at DESC LIMIT :limit');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function caseOptions(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.reference_code, c.summary, c.type, c.status, cust.full_name
             FROM cases c
             INNER JOIN customers cust ON cust.id = c.customer_id
             WHERE c.status NOT IN (\'opgehaald\', \'gesloten\')
             ORDER BY c.updated_at DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentMovements(int $limit = 15): array
    {
        $statement = $this->pdo->prepare(
            'SELECT wm.*, wi.name AS item_name, wi.reference_code AS item_reference, wi.barcode AS item_barcode,
                    c.reference_code AS case_reference_code, cust.full_name AS customer_name
             FROM warehouse_movements wm
             LEFT JOIN warehouse_items wi ON wi.id = wm.item_id
             LEFT JOIN cases c ON c.id = wm.case_id
             LEFT JOIN customers cust ON cust.id = c.customer_id
             ORDER BY wm.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRawItem(int $itemId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM warehouse_items WHERE id = :id');
        $statement->execute(['id' => $itemId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    private function normalizeReferenceCode(?string $referenceCode): string
    {
        $filtered = preg_replace('/[^A-Za-z0-9-]/', '', (string) $referenceCode);
        if (!is_string($filtered)) {
            $filtered = '';
        }

        $reference = strtoupper($filtered);
        if ($reference === '') {
            return $this->generateReferenceCode();
        }

        if ($this->referenceExists($reference)) {
            return $this->generateReferenceCode();
        }

        return $reference;
    }

    private function referenceExists(string $reference): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM warehouse_items WHERE reference_code = :reference LIMIT 1');
        $statement->execute(['reference' => $reference]);

        return (bool) $statement->fetchColumn();
    }

    private function generateReferenceCode(): string
    {
        do {
            $candidate = 'WH' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
        } while ($this->referenceExists($candidate));

        return $candidate;
    }

    private function generateBarcodeValue(?string $base): string
    {
        $value = BarcodeService::sanitize($base ?? '');
        if ($value === '') {
            $value = 'WH' . strtoupper(bin2hex(random_bytes(3)));
        }

        while ($this->barcodeExists($value)) {
            $value = BarcodeService::sanitize($value . strtoupper(bin2hex(random_bytes(1))));
            if ($value === '') {
                $value = 'WH' . strtoupper(bin2hex(random_bytes(3)));
            }
        }

        return $value;
    }

    private function barcodeExists(string $barcode): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM warehouse_items WHERE barcode = :barcode LIMIT 1');
        $statement->execute(['barcode' => $barcode]);

        return (bool) $statement->fetchColumn();
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function quantityDelta(string $movementType, int $quantity): int
    {
        return match ($movementType) {
            'inbound' => $quantity,
            'outbound' => -$quantity,
            'adjustment' => $quantity,
            default => 0,
        };
    }

    private function reservedDelta(string $movementType, int $quantity): int
    {
        return match ($movementType) {
            'reserve' => $quantity,
            'release' => -$quantity,
            default => 0,
        };
    }
}