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

        self::assertExtensionLoaded($config->driver());

        try {
            $pdo = self::createConnection($config);
        } catch (PDOException $exception) {
            if ($config->driver() === 'mysql' && self::isUnknownDatabaseError($exception)) {
                self::createDatabase($config);
                $pdo = self::createConnection($config);
            } else {
                throw self::connectionException($exception, $config);
            }
        }

        self::$pdo = $pdo;

        return self::$pdo;
    }

    private static function createConnection(AppConfig $config): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($config->driver() === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }
        if ($config->driver() === 'sqlite') {
            $sqlitePath = $config->sqlitePath();

            if ($sqlitePath === null || $sqlitePath === '') {
                throw new RuntimeException('SQLite database pad is niet geconfigureerd.');
            }

            $directory = dirname($sqlitePath);

            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Kan de SQLite map "%s" niet aanmaken.', $directory));
            }

            self::migrateLegacySqliteDatabase($sqlitePath);
        }

        $username = $config->driver() === 'sqlite' ? null : $config->dbUser();
        $password = $config->driver() === 'sqlite' ? null : $config->dbPassword();

        $pdo = new PDO(
            $config->dsn(),
            $username,
            $password,
            $options
        );

        if ($config->driver() === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    private static function createDatabase(AppConfig $config): void
    {
        if ($config->driver() !== 'mysql') {
            throw new RuntimeException(sprintf('Database "%s" bestaat nog niet of is niet bereikbaar.', $config->dbName()));
        }
        $pdo = new PDO(
            $config->dsnWithoutDatabase(),
            $config->dbUser(),
            $config->dbPassword(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $databaseName = str_replace('`', '``', $config->dbName());
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $databaseName
        ));
    }

    private static function migrateLegacySqliteDatabase(string $sqlitePath): void
    {
        $directory = dirname($sqlitePath);
        $basename = basename($sqlitePath);

        if ($basename === '') {
            return;
        }

        $legacyPath = $directory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . $basename;

        if ($legacyPath === $sqlitePath || is_file($sqlitePath) || !is_file($legacyPath)) {
            return;
        }

        if (!@rename($legacyPath, $sqlitePath)) {
            if (!@copy($legacyPath, $sqlitePath)) {
                throw new RuntimeException(sprintf(
                    'Kon het bestaande SQLite bestand niet migreren van "%s" naar "%s".',
                    $legacyPath,
                    $sqlitePath
                ));
            }

            @unlink($legacyPath);
        }
    }

    private static function isUnknownDatabaseError(PDOException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        if (in_array($sqlState, ['3D000', '42000'], true)) {
            return true;
        }
        $errorCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($errorCode === 1049) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unknown database')
            || str_contains($message, 'does not exist')
            || str_contains($message, 'unknown db');
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
            return match ($config->driver()) {
                'pgsql' => 'De PDO PostgreSQL-extensie (pdo_pgsql) is niet beschikbaar. Schakel deze extensie in php.ini in of installeer de PostgreSQL-driver.',
                'sqlite' => 'De PDO SQLite-extensie (pdo_sqlite) is niet beschikbaar. Schakel deze extensie in php.ini in om SQLite te gebruiken.',
                default => 'De PDO MySQL-extensie (pdo_mysql) is niet beschikbaar. Schakel deze extensie in php.ini in of installeer de MySQL-driver.',
            };
        }

        if (str_contains($message, 'access denied') || str_contains($message, 'authentication failed')) {
            $baseMessage = sprintf(
                'De database weigerde de verbinding voor gebruiker "%s". Controleer de gebruikersnaam en het wachtwoord in het .env-bestand.',
                $config->dbUser()
            );

            $projectRoot = dirname(__DIR__, 2);
            $envFile = $projectRoot . '/.env';
            $envExample = $projectRoot . '/.env.example';

            if (!is_file($envFile) && is_file($envExample)) {
                $baseMessage .= ' Het lijkt erop dat er nog geen .env-bestand aanwezig is. Kopieer het bestand .env.example naar .env en vul daar de juiste databasegegevens in.';
            } elseif ($config->dbUser() === 'digivriend') {
                $baseMessage .= ' Er wordt nog gebruikgemaakt van de standaard databasegebruiker "digivriend". Pas de waarden in .env aan naar de gegevens van jouw database (of stel de variabele DB_URL in).';
            }

            return $baseMessage;
        }

        if (
            str_contains($message, 'sqlstate[hy000] [2002]') ||
            str_contains($message, 'connection refused') ||
            str_contains($message, 'server has gone away') ||
            str_contains($message, 'timeout expired') ||
            str_contains($message, 'could not translate host name') ||
            str_contains($message, 'php_network_getaddresses')
        ) {
            $databaseName = $config->driver() === 'pgsql' ? 'PostgreSQL' : 'MySQL';
            return sprintf(
                'Er kon geen verbinding worden gemaakt met %s op %s:%d. Controleer of de server actief is en of de host/poort juist zijn ingesteld.',
                $databaseName,
                $config->dbHost(),
                $config->dbPort()
            );
        }

        if ($config->driver() === 'sqlite' && (str_contains($message, 'unable to open database file') || str_contains($message, 'attempt to write a readonly database'))) {
            return 'De SQLite database kon niet worden geopend. Controleer of het pad uit het .env-bestand bestaat en of PHP schrijfrechten heeft.';
        }

        if ($config->driver() === 'mysql' && self::isUnknownDatabaseError($exception)) {
            return sprintf(
                'De database "%s" bestaat nog niet of is niet bereikbaar. Controleer of de gebruiker "%s" de juiste rechten heeft om deze aan te maken.',
                $config->dbName(),
                $config->dbUser()
            );
        }

        return 'Er is een fout opgetreden bij het verbinden met de database.';
    }

    private static function assertExtensionLoaded(string $driver): void
    {
        $extension = match ($driver) {
            'pgsql' => 'pdo_pgsql',
            'sqlite' => 'pdo_sqlite',
            default => 'pdo_mysql',
        };

        if (!extension_loaded($extension)) {
            $friendly = match ($driver) {
                'pgsql' => 'De PDO PostgreSQL-extensie (pdo_pgsql) is niet ingeschakeld. Schakel deze extensie in php.ini in of installeer de PostgreSQL-driver om verbinding te maken.',
                'sqlite' => 'De PDO SQLite-extensie (pdo_sqlite) is niet ingeschakeld. Schakel deze extensie in php.ini in om SQLite te gebruiken.',
                default => 'De PDO MySQL-extensie (pdo_mysql) is niet ingeschakeld. Schakel deze extensie in php.ini in of installeer de MySQL-driver om verbinding te maken.',
            };

            throw new RuntimeException($friendly);
        }
    }
}