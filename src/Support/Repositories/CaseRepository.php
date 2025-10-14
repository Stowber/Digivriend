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
        $shouldClose = in_array($status, ['opgehaald', 'gesloten'], true);

        $statement = $this->pdo->prepare(
            'UPDATE cases SET status = :status, closed_at = CASE WHEN :should_close = 1 THEN :closed_at ELSE closed_at END WHERE id = :id'
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

        return $statement->fetchAll() ?: [];
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

        return $statement->fetchAll() ?: [];
    }

    private function updateCase(int $caseId, string $status, ?string $summary, array $details): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cases SET status = :status, summary = COALESCE(:summary, summary), details = :details WHERE id = :id'
        );
        $statement->execute([
            'id' => $caseId,
            'status' => $status,
            'summary' => $summary,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}