<?php

declare(strict_types=1);

namespace App\Database;

use App\Config\AppConfig;
use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    private static ?PDO $pdo = null;

    public static function make(?AppConfig $config = null): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config ??= AppConfig::load();

        try {
             $pdo = self::createConnection($config);
        } catch (PDOException $exception) {
             if (self::isUnknownDatabaseError($exception)) {
                try {
                    self::createDatabase($config);
                    $pdo = self::createConnection($config);
                } catch (PDOException $innerException) {
                    throw self::connectionException($innerException, $config);
                }
            } else {
                throw self::connectionException($exception, $config);
            }
        }

        self::$pdo = $pdo;

        return self::$pdo;
    }
    private static function createConnection(AppConfig $config): PDO
    {
        return new PDO(
            $config->dsn(),
            $config->dbUser(),
            $config->dbPassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private static function createDatabase(AppConfig $config): void
    {
        $pdo = new PDO(
            $config->dsnWithoutDatabase(),
            $config->dbUser(),
            $config->dbPassword(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]
        );

        $databaseName = str_replace('`', '``', $config->dbName());
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $databaseName
        ));
    }

    private static function isUnknownDatabaseError(PDOException $exception): bool
    {
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($errorCode === 1049) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'unknown database');
    }

    private static function connectionException(PDOException $exception, AppConfig $config): RuntimeException
    {
        if ($config->isDebug()) {
            return new RuntimeException('Kon geen verbinding maken met de database: ' . $exception->getMessage(), 0, $exception);
        }

        return new RuntimeException('Er is een fout opgetreden bij het verbinden met de database.', 0, $exception);
    }
}