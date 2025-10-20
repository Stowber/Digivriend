<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\SchemaManager;
use PDO;
use PDOException;
use RuntimeException;

final class DatabaseResetService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function reset(): void
    {
        $driver = strtolower((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

        try {
            switch ($driver) {
                case 'sqlite':
                    $this->resetSqlite();
                    break;
                case 'mysql':
                    $this->resetMysql();
                    break;
                case 'pgsql':
                    $this->resetPostgres();
                    break;
                default:
                    throw new RuntimeException(sprintf('Nieobsługiwany sterownik bazy danych: %s', $driver));
            }

            SchemaManager::migrate($this->pdo);
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Nie udało się wyczyścić bazy danych: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        }
    }

    private function resetSqlite(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');

        try {
            $statement = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            if ($statement === false) {
                return;
            }

            $tableNames = $statement->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tableNames as $table) {
                if (!is_string($table) || $table === '') {
                    continue;
                }

                $quoted = $this->quoteIdentifier($table, 'sqlite');
                $this->pdo->exec(sprintf('DELETE FROM %s', $quoted));
            }

            try {
                $this->pdo->exec('DELETE FROM sqlite_sequence');
            } catch (PDOException) {
                // Ignorujemy, gdy tabela nie istnieje.
            }
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private function resetMysql(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            $statement = $this->pdo->query('SHOW TABLES');
            if ($statement === false) {
                return;
            }

            $tableNames = $statement->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tableNames as $table) {
                if (!is_string($table) || $table === '') {
                    continue;
                }

                $quoted = $this->quoteIdentifier($table, 'mysql');
                $this->pdo->exec(sprintf('TRUNCATE TABLE %s', $quoted));
            }
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function resetPostgres(): void
    {
        $statement = $this->pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        if ($statement === false) {
            return;
        }

        $tableNames = array_filter(
            $statement->fetchAll(PDO::FETCH_COLUMN) ?: [],
            static fn ($value): bool => is_string($value) && $value !== ''
        );

        if ($tableNames === []) {
            return;
        }

        $quotedTables = array_map(
            fn (string $table): string => $this->quoteIdentifier($table, 'pgsql'),
            $tableNames
        );

        $sql = sprintf(
            'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
            implode(', ', $quotedTables)
        );

        $this->pdo->exec($sql);
    }

    private function quoteIdentifier(string $identifier, string $driver): string
    {
        return match ($driver) {
            'mysql' => '`' . str_replace('`', '``', $identifier) . '`',
            default => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }
}