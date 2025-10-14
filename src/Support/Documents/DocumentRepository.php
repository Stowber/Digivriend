<?php

declare(strict_types=1);

namespace App\Support\Documents;

use App\Support\Clock;
use PDO;

final class DocumentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(?int $caseId, string $type, string $filePath, array $metadata = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO documents (case_id, type, file_path, metadata, created_at) VALUES (:case_id, :type, :file_path, :metadata, :created_at)'
        );

        $statement->execute([
            'case_id' => $caseId,
            'type' => $type,
            'file_path' => $filePath,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => Clock::nowFormatted(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM documents ORDER BY created_at DESC LIMIT :limit');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCase(int $caseId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM documents WHERE case_id = :case_id ORDER BY created_at DESC');
        $statement->execute(['case_id' => $caseId]);

        return $statement->fetchAll() ?: [];
    }
}