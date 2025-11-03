<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class AppointmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<int, int> $employeeIds
     * @param array<int, array{type:string,label:string,details?:string|null}> $resources
     */
    public function create(
        string $title,
        string $type,
        string $status,
        string $startAt,
        string $endAt,
        ?int $caseId,
        ?int $customerId,
        ?string $location,
        ?string $notes,
        ?string $color,
        ?string $confirmationMethod,
        ?string $customerConfirmationStatus,
        ?string $customerConfirmedAt,
        ?string $createdBy,
        ?string $updatedBy,
        array $employeeIds,
        array $resources
    ): array {
        $statement = $this->pdo->prepare(
            'INSERT INTO appointments (title, appointment_type, status, start_at, end_at, case_id, customer_id, location, notes, color, confirmation_method, customer_confirmation_status, customer_confirmed_at, created_by, updated_by)
             VALUES (:title, :type, :status, :start_at, :end_at, :case_id, :customer_id, :location, :notes, :color, :confirmation_method, :customer_confirmation_status, :customer_confirmed_at, :created_by, :updated_by)'
        );

        $statement->execute([
            'title' => $title,
            'type' => $type,
            'status' => $status,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'case_id' => $caseId,
            'customer_id' => $customerId,
            'location' => $location,
            'notes' => $notes,
            'color' => $color,
            'confirmation_method' => $confirmationMethod,
            'customer_confirmation_status' => $customerConfirmationStatus,
            'customer_confirmed_at' => $customerConfirmedAt,
            'created_by' => $createdBy,
            'updated_by' => $updatedBy,
        ]);

        $appointmentId = (int) $this->pdo->lastInsertId();

        foreach ($employeeIds as $employeeId) {
            $this->addAttendee($appointmentId, (int) $employeeId, 'participant', true);
        }

        foreach ($resources as $resource) {
            $this->addResource(
                $appointmentId,
                (string) ($resource['type'] ?? 'resource'),
                (string) ($resource['label'] ?? ''),
                $resource['details'] ?? null
            );
        }

        return $this->find($appointmentId) ?? [];
    }

    public function find(int $appointmentId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM appointments WHERE id = :id');
        $statement->execute(['id' => $appointmentId]);
        $appointment = $statement->fetch();

        if ($appointment === false) {
            return null;
        }

        $appointment['attendees'] = $this->attendeesForAppointment($appointmentId);
        $appointment['resources'] = $this->resourcesForAppointment($appointmentId);

        return $appointment;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function attendeesForAppointment(int $appointmentId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT aa.*, e.full_name
             FROM appointment_attendees aa
             LEFT JOIN employees e ON e.id = aa.employee_id
             WHERE aa.appointment_id = :appointment_id
             ORDER BY aa.created_at'
        );
        $statement->execute(['appointment_id' => $appointmentId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resourcesForAppointment(int $appointmentId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM appointment_resources WHERE appointment_id = :appointment_id ORDER BY created_at');
        $statement->execute(['appointment_id' => $appointmentId]);

        return $statement->fetchAll() ?: [];
    }

    public function addAttendee(int $appointmentId, int $employeeId, string $role, bool $required): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO appointment_attendees (appointment_id, employee_id, attendee_role, is_required)
             VALUES (:appointment_id, :employee_id, :role, :required)'
        );
        $statement->execute([
            'appointment_id' => $appointmentId,
            'employee_id' => $employeeId,
            'role' => $role,
            'required' => $required ? 1 : 0,
        ]);
    }

    public function addResource(int $appointmentId, string $type, string $label, ?string $details): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO appointment_resources (appointment_id, resource_type, resource_label, details)
             VALUES (:appointment_id, :type, :label, :details)'
        );
        $statement->execute([
            'appointment_id' => $appointmentId,
            'type' => $type,
            'label' => $label,
            'details' => $details,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCase(int $caseId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM appointments WHERE case_id = :case_id ORDER BY start_at DESC');
        $statement->execute(['case_id' => $caseId]);
        $appointments = $statement->fetchAll() ?: [];

        foreach ($appointments as &$appointment) {
            $appointmentId = (int) ($appointment['id'] ?? 0);
            if ($appointmentId > 0) {
                $appointment['attendees'] = $this->attendeesForAppointment($appointmentId);
                $appointment['resources'] = $this->resourcesForAppointment($appointmentId);
            }
        }

        return $appointments;
    }

    public function updateStatus(int $appointmentId, string $status, ?string $updatedBy = null): void
    {
        $statement = $this->pdo->prepare('UPDATE appointments SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute([
            'id' => $appointmentId,
            'status' => $status,
            'updated_by' => $updatedBy,
        ]);
    }

    public function updateSchedule(int $appointmentId, string $startAt, string $endAt, ?string $updatedBy = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE appointments
             SET start_at = :start_at,
                 end_at = :end_at,
                 updated_by = :updated_by,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $appointmentId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'updated_by' => $updatedBy,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function upcoming(?string $from, ?string $to, ?int $employeeId = null, ?string $status = null, int $limit = 50): array
    {
        $sql = 'SELECT a.* FROM appointments a';
        $conditions = [];
        $params = [];

        if ($employeeId !== null) {
            $sql .= ' INNER JOIN appointment_attendees aa ON aa.appointment_id = a.id';
            $conditions[] = 'aa.employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }

        if ($from !== null) {
            $conditions[] = 'a.end_at >= :from';
            $params['from'] = $from;
        }

        if ($to !== null) {
            $conditions[] = 'a.start_at <= :to';
            $params['to'] = $to;
        }

        if ($status !== null && $status !== 'all') {
            $conditions[] = 'a.status = :status';
            $params['status'] = $status;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY a.start_at LIMIT :limit';

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $paramType = $name === 'employee_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue(':' . $name, $value, $paramType);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $appointments = $statement->fetchAll() ?: [];

        foreach ($appointments as &$appointment) {
            $appointmentId = (int) ($appointment['id'] ?? 0);
            if ($appointmentId > 0) {
                $appointment['attendees'] = $this->attendeesForAppointment($appointmentId);
                $appointment['resources'] = $this->resourcesForAppointment($appointmentId);
            }
        }

        return $appointments;
    }

    /**
     * @param array<int, int> $employeeIds
     * @return array<int, array<string, mixed>>
     */
    public function conflicts(string $startAt, string $endAt, array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($employeeIds), '?'));
        $sql = 'SELECT a.*, aa.employee_id FROM appointments a
                INNER JOIN appointment_attendees aa ON aa.appointment_id = a.id
                WHERE aa.employee_id IN (' . $placeholders . ')
                AND a.status NOT IN (\'cancelled\')
                AND a.start_at < :end_at AND a.end_at > :start_at';

        $statement = $this->pdo->prepare($sql);
        $index = 1;
        foreach ($employeeIds as $employeeId) {
            $statement->bindValue($index, $employeeId, PDO::PARAM_INT);
            $index++;
        }
        $statement->bindValue(':end_at', $endAt);
        $statement->bindValue(':start_at', $startAt);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }
}