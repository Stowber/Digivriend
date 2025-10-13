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
            $pdo = new PDO(
                $config->dsn(),
                $config->dbUser(),
                $config->dbPassword(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        } catch (PDOException $exception) {
            if ($config->isDebug()) {
                throw new RuntimeException('Kon geen verbinding maken met de database: ' . $exception->getMessage(), 0, $exception);
            }

            throw new RuntimeException('Er is een fout opgetreden bij het verbinden met de database.');
        }

        self::$pdo = $pdo;

        return self::$pdo;
    }
}