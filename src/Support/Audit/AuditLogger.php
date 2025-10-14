<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Support\Clock;
use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(int $caseId, ?int $userId, string $username, string $action, array $context = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO case_audit_logs (case_id, user_id, username, action, context, created_at) VALUES (:case_id, :user_id, :username, :action, :context, :created_at)'
        );

        $statement->execute([
            'case_id' => $caseId,
            'user_id' => $userId,
            'username' => $username,
            'action' => $action,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'created_at' => Clock::nowFormatted(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentForCase(int $caseId, int $limit = 20): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM case_audit_logs WHERE case_id = :case_id ORDER BY created_at DESC LIMIT :limit'
        );
        $statement->bindValue('case_id', $caseId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }
}