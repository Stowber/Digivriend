<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class NoteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(int $caseId, int $customerId, string $author, string $body): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notes (case_id, customer_id, author, body) VALUES (:case_id, :customer_id, :author, :body)'
        );
        $statement->execute([
            'case_id' => $caseId,
            'customer_id' => $customerId,
            'author' => $author,
            'body' => $body,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCase(int $caseId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM notes WHERE case_id = :case_id ORDER BY created_at DESC');
        $statement->execute(['case_id' => $caseId]);

        return $statement->fetchAll() ?: [];
    }
}