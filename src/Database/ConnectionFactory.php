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

        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException(
                'De PDO MySQL-extensie (pdo_mysql) is niet ingeschakeld. Schakel deze extensie in php.ini in of installeer de MySQL-driver om verbinding te maken.'
            );
        }

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

         return new RuntimeException(self::friendlyMessage($exception, $config), 0, $exception);
    }

    private static function friendlyMessage(PDOException $exception, AppConfig $config): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'could not find driver')) {
            return 'De PDO MySQL-extensie (pdo_mysql) is niet beschikbaar. Schakel deze extensie in php.ini in of installeer de MySQL-driver.';
        }

        if (str_contains($message, 'access denied')) {
            return sprintf(
                'De database weigerde de verbinding voor gebruiker "%s". Controleer de gebruikersnaam en het wachtwoord in het .env-bestand.',
                $config->dbUser()
            );
        }

        if (
            str_contains($message, 'sqlstate[hy000] [2002]') ||
            str_contains($message, 'connection refused') ||
            str_contains($message, 'server has gone away')
        ) {
            return sprintf(
                'Er kon geen verbinding worden gemaakt met MySQL op %s:%d. Controleer of de server actief is en of de host/poort juist zijn ingesteld.',
                $config->dbHost(),
                $config->dbPort()
            );
        }

        if (self::isUnknownDatabaseError($exception)) {
            return sprintf(
                'De database "%s" bestaat nog niet of is niet bereikbaar. Controleer of de gebruiker "%s" de juiste rechten heeft om deze aan te maken.',
                $config->dbName(),
                $config->dbUser()
            );
        }

        return 'Er is een fout opgetreden bij het verbinden met de database.';
    }
}