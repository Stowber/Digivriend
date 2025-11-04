<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function updateLanguageByUsername(string $username, string $language): void
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET language = :language, updated_at = CURRENT_TIMESTAMP WHERE LOWER(username) = :username'
        );

        $statement->execute([
            'language' => $language,
            'username' => $normalized,
        ]);
    }
}