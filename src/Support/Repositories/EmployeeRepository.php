<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class EmployeeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $status = null): array
    {
        $sql = 'SELECT * FROM employees';
        $params = [];

        if ($status !== null && $status !== 'all') {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $sql .= " ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, full_name";

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function find(int $employeeId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM employees WHERE id = :id');
        $statement->execute(['id' => $employeeId]);
        $record = $statement->fetch();

        return $record !== false ? $record : null;
    }

    public function findByUsername(string $username): ?array
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM employees WHERE LOWER(email) = :username OR LOWER(full_name) = :username LIMIT 1'
        );
        $statement->execute(['username' => $normalized]);
        $record = $statement->fetch();

        return $record !== false ? $record : null;
    }

    public function create(
        string $fullName,
        ?string $email,
        ?string $phone,
        string $role,
        ?string $department,
        ?string $position,
        ?string $color,
        ?string $timezone,
        ?string $language,
        array $permissions,
        ?string $hiredAt
    ): array {
        $statement = $this->pdo->prepare(
            'INSERT INTO employees (full_name, email, phone, role, department, position, color, timezone, language, permissions, status, hired_at)
             VALUES (:full_name, :email, :phone, :role, :department, :position, :color, :timezone, :language, :permissions, :status, :hired_at)'
        );

        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'department' => $department,
            'position' => $position,
            'color' => $color,
            'timezone' => $timezone,
            'language' => $language,
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'status' => 'active',
            'hired_at' => $hiredAt,
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function update(
        int $employeeId,
        string $fullName,
        ?string $email,
        ?string $phone,
        string $role,
        ?string $department,
        ?string $position,
        ?string $color,
        ?string $timezone,
        ?string $language,
        array $permissions,
        ?string $hiredAt,
        ?string $terminatedAt
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE employees
             SET full_name = :full_name,
                 email = :email,
                 phone = :phone,
                 role = :role,
                 department = :department,
                 position = :position,
                 color = :color,
                 timezone = :timezone,
                 language = :language,
                 permissions = :permissions,
                 hired_at = :hired_at,
                 terminated_at = :terminated_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $employeeId,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'department' => $department,
            'position' => $position,
            'color' => $color,
            'timezone' => $timezone,
            'language' => $language,
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'hired_at' => $hiredAt,
            'terminated_at' => $terminatedAt,
        ]);
    }

    public function updateLanguageByUsername(string $username, string $language): void
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE employees SET language = :language, updated_at = CURRENT_TIMESTAMP WHERE LOWER(email) = :username OR LOWER(full_name) = :username'
        );

        $statement->execute([
            'language' => $language,
            'username' => $normalized,
        ]);
    }

    public function changeStatus(int $employeeId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE employees SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([
            'id' => $employeeId,
            'status' => $status,
        ]);
    }

    public function logAudit(int $employeeId, string $action, array $context, ?string $performedBy): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO employee_audit_log (employee_id, action, context, performed_by) VALUES (:employee_id, :action, :context, :performed_by)'
        );

        $statement->execute([
            'employee_id' => $employeeId,
            'action' => $action,
            'context' => json_encode($context, JSON_THROW_ON_ERROR),
            'performed_by' => $performedBy,
        ]);
    }

    public function addAvailability(
        int $employeeId,
        string $type,
        string $startAt,
        string $endAt,
        ?string $reason
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO employee_availability (employee_id, availability_type, reason, start_at, end_at)
             VALUES (:employee_id, :type, :reason, :start_at, :end_at)'
        );

        $statement->execute([
            'employee_id' => $employeeId,
            'type' => $type,
            'reason' => $reason,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availabilityForEmployee(int $employeeId, ?string $from = null, ?string $to = null): array
    {
        $sql = 'SELECT * FROM employee_availability WHERE employee_id = :employee_id';
        $params = ['employee_id' => $employeeId];

        if ($from !== null) {
            $sql .= ' AND end_at >= :from';
            $params['from'] = $from;
        }

        if ($to !== null) {
            $sql .= ' AND start_at <= :to';
            $params['to'] = $to;
        }

        $sql .= ' ORDER BY start_at';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function assignmentsForEmployee(int $employeeId, int $limit = 10): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ca.*, c.summary, c.status, c.type
             FROM case_assignments ca
             INNER JOIN cases c ON c.id = ca.case_id
             WHERE ca.employee_id = :employee_id AND (ca.unassigned_at IS NULL OR ca.unassigned_at > CURRENT_TIMESTAMP)
             ORDER BY ca.assigned_at DESC
             LIMIT :limit'
        );
        $statement->bindValue('employee_id', $employeeId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }
}