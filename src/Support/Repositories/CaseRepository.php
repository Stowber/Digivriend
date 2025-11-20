<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;

final class CaseRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createOrUpdate(
        string $type,
        int $customerId,
        ?int $deviceId,
        string $status,
        ?string $summary = null,
        ?string $referenceCode = null,
        array $details = []
    ): array {
        $case = null;

        if ($referenceCode !== null) {
            $case = $this->findByReference($type, $referenceCode);
        }

        if ($case === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO cases (type, customer_id, device_id, status, summary, reference_code, details)
                 VALUES (:type, :customer_id, :device_id, :status, :summary, :reference_code, :details)'
            );
            $statement->execute([
                'type' => $type,
                'customer_id' => $customerId,
                'device_id' => $deviceId,
                'status' => $status,
                'summary' => $summary,
                'reference_code' => $referenceCode,
                'details' => json_encode($details, JSON_THROW_ON_ERROR),
            ]);

            return $this->findById((int) $this->pdo->lastInsertId());
        }

        $this->updateCase((int) $case['id'], $status, $summary, $details);

        return $this->findById((int) $case['id']);
    }

    public function updateStatus(int $caseId, string $status): void
    {
        $shouldClose = in_array($status, ['opgehaald', 'gesloten', 'geannuleerd'], true);
        $statement = $this->pdo->prepare(
            'UPDATE cases
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP,
                 closed_at = CASE WHEN :should_close = 1 THEN :closed_at ELSE closed_at END
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $caseId,
            'should_close' => $shouldClose ? 1 : 0,
            'closed_at' => $shouldClose ? Clock::nowFormatted() : null,
        ]);
    }

    public function findById(int $caseId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cases WHERE id = :id');
        $statement->execute(['id' => $caseId]);
        $case = $statement->fetch();

        return $case !== false ? $case : null;
    }

    public function findByReference(string $type, string $referenceCode): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cases WHERE type = :type AND reference_code = :reference LIMIT 1');
        $statement->execute([
            'type' => $type,
            'reference' => $referenceCode,
        ]);
        $case = $statement->fetch();

        return $case !== false ? $case : null;
    }

    public function findByReferenceCode(string $referenceCode): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cases WHERE reference_code = :reference LIMIT 1');
        $statement->execute(['reference' => $referenceCode]);
        $case = $statement->fetch();

        return $case !== false ? $case : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCustomer(int $customerId, int $limit = 25): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial
             FROM cases c
             LEFT JOIN devices dev ON dev.id = c.device_id
             WHERE c.customer_id = :customer_id
             ORDER BY c.created_at DESC
             LIMIT :limit'
        );
        $statement->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forPartner(int $partnerId, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.*, cust.full_name AS customer_name, cust.phone AS customer_phone, cust.email AS customer_email
             FROM cases c
             INNER JOIN customers cust ON cust.id = c.customer_id
             WHERE c.details LIKE :partner_pattern
             ORDER BY c.updated_at DESC
             LIMIT :limit'
        );
        $statement->bindValue(':partner_pattern', '%"partner_id":' . $partnerId . '%');
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        $cases = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($cases as &$case) {
            $decodedDetails = [];
            if (!empty($case['details'])) {
                $parsed = json_decode((string) $case['details'], true);
                if (is_array($parsed)) {
                    $decodedDetails = $parsed;
                }
            }

            $case['details'] = $decodedDetails;
            $case['partner_assigned_at'] = isset($decodedDetails['partner_assigned_at'])
                ? (string) $decodedDetails['partner_assigned_at']
                : null;
        }
        unset($case);

        return $cases;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenPickups(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cases WHERE type = :type AND status = :status ORDER BY updated_at DESC');
        $statement->execute([
            'type' => 'pickup',
            'status' => 'klaar',
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentCases(int $limit = 10, ?string $type = null, ?string $since = null): array
    {
        $sql = 'SELECT * FROM cases';
        $conditions = [];
        $parameters = [];

        if ($type !== null && $type !== '' && $type !== 'all') {
            $conditions[] = 'type = :type';
            $parameters[':type'] = $type;
        }

        if ($since !== null && $since !== '') {
            $conditions[] = 'updated_at >= :since';
            $parameters[':since'] = $since;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $placeholder => $value) {
            $statement->bindValue($placeholder, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentCaseOverview(int $limit = 25, ?string $type = null): array
    {
        $sql = 'SELECT c.*, cust.full_name, cust.email, cust.phone, cust.address, cust.postal_code, cust.city,'
            . ' dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial, dev.device_type'
            . ' FROM cases c'
            . ' INNER JOIN customers cust ON cust.id = c.customer_id'
            . ' LEFT JOIN devices dev ON dev.id = c.device_id';

        $params = [];
        $conditions = ['c.closed_at IS NULL'];
        if ($type !== null && $type !== '' && $type !== 'all') {
            $conditions[] = 'c.type = :type';
            $params['type'] = $type;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY c.created_at DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function assignments(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ca.*, e.full_name, e.role
             FROM case_assignments ca
             INNER JOIN employees e ON e.id = ca.employee_id
             WHERE ca.case_id = :case_id
             ORDER BY ca.assigned_at DESC'
        );
        $statement->execute(['case_id' => $caseId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, array{employee_id:int, type:string, notes?:string|null}> $assignments
     */
    public function syncAssignments(int $caseId, array $assignments, string $assignedBy): void
    {
        $currentAssignments = $this->assignments($caseId);
        $activeMap = [];

        foreach ($currentAssignments as $assignment) {
            if (!empty($assignment['unassigned_at'])) {
                continue;
            }

            $key = $assignment['employee_id'] . ':' . ($assignment['assignment_type'] ?? 'primary');
            $activeMap[$key] = $assignment;
        }

        $newKeys = [];
        foreach ($assignments as $entry) {
            $employeeId = (int) ($entry['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }

            $type = trim((string) ($entry['type'] ?? 'primary'));
            if ($type === '') {
                $type = 'primary';
            }
            $notes = isset($entry['notes']) ? trim((string) $entry['notes']) : null;
            $key = $employeeId . ':' . $type;
            $newKeys[$key] = true;

            if (isset($activeMap[$key])) {
                continue;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO case_assignments (case_id, employee_id, assignment_type, assigned_by, notes)
                 VALUES (:case_id, :employee_id, :type, :assigned_by, :notes)'
            );
            $insert->execute([
                'case_id' => $caseId,
                'employee_id' => $employeeId,
                'type' => $type,
                'assigned_by' => $assignedBy,
                'notes' => $notes,
            ]);
        }

        foreach ($activeMap as $key => $assignment) {
            if (isset($newKeys[$key])) {
                continue;
            }

            $update = $this->pdo->prepare('UPDATE case_assignments SET unassigned_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute(['id' => $assignment['id']]);
        }
    }

    public function updateMeta(int $caseId, ?string $priority, ?string $slaDueAt, ?int $primaryEmployeeId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cases
             SET priority = :priority,
                 sla_due_at = :sla_due_at,
                 primary_employee_id = :primary_employee_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $caseId,
            'priority' => $priority !== null && $priority !== '' ? $priority : null,
            'sla_due_at' => $slaDueAt !== null && $slaDueAt !== '' ? $slaDueAt : null,
            'primary_employee_id' => $primaryEmployeeId,
        ]);
    }

    private function updateCase(int $caseId, string $status, ?string $summary, array $details): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cases
             SET status = :status,
                 summary = COALESCE(:summary, summary),
                 details = :details,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $caseId,
            'status' => $status,
            'summary' => $summary,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
    public function updateDetails(int $caseId, array $details): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cases
             SET details = :details,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $caseId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }

    public function updateStatusAndDetails(int $caseId, string $status, array $details): void
    {
        $shouldClose = in_array($status, ['opgehaald', 'gesloten', 'geannuleerd'], true);
        $statement = $this->pdo->prepare(
            'UPDATE cases
             SET status = :status,
                 details = :details,
                 updated_at = CURRENT_TIMESTAMP,
                 closed_at = CASE WHEN :should_close = 1 THEN :closed_at ELSE closed_at END
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $caseId,
            'status' => $status,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'should_close' => $shouldClose ? 1 : 0,
            'closed_at' => $shouldClose ? Clock::nowFormatted() : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function archivedCases(int $limit = 50, ?string $type = null): array
    {
        $sql = 'SELECT c.*, cust.full_name, cust.email, cust.phone, cust.address, cust.postal_code, cust.city,'
            . ' dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial, dev.device_type'
            . ' FROM cases c'
            . ' INNER JOIN customers cust ON cust.id = c.customer_id'
            . ' LEFT JOIN devices dev ON dev.id = c.device_id'
            . ' WHERE c.closed_at IS NOT NULL';

        $params = [];
        if ($type !== null && $type !== '' && $type !== 'all') {
            $sql .= ' AND c.type = :type';
            $params['type'] = $type;
        }

        $sql .= ' ORDER BY (c.closed_at IS NULL) ASC, c.closed_at DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}