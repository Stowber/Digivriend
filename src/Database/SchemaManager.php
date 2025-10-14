<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Env;
use PDO;
use PDOException;

final class SchemaManager
{
    public static function migrate(PDO $pdo): void
    {
        $tables = [
            'CREATE TABLE IF NOT EXISTS customers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(191) NOT NULL,
                email VARCHAR(191) NULL,
                phone VARCHAR(64) NULL,
                address VARCHAR(255) NULL,
                postal_code VARCHAR(32) NULL,
                city VARCHAR(120) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                last_interaction_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uniq_customers_email (email),
                INDEX idx_customers_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS devices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                brand VARCHAR(120) NULL,
                model VARCHAR(191) NULL,
                serial_number VARCHAR(120) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_device_customer_serial (customer_id, serial_number),
                INDEX idx_devices_customer (customer_id),
                CONSTRAINT fk_devices_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS cases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                device_id INT UNSIGNED NULL,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(64) NOT NULL DEFAULT "open",
                reference_code VARCHAR(64) NULL,
                summary VARCHAR(255) NULL,
                details JSON NULL,
                opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                closed_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_cases_customer (customer_id),
                INDEX idx_cases_reference (reference_code),
                INDEX idx_cases_status (status),
                CONSTRAINT fk_cases_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_cases_devices FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NOT NULL,
                author VARCHAR(120) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_notes_cases FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_notes_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(64) NOT NULL DEFAULT "staff",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ];

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }

        self::ensureOphaalbevestigingColumns($pdo);
        self::ensureReparatieOnderzoekColumns($pdo);
        self::ensureDataRecoveryColumns($pdo);
        self::ensureDefaultUserExists($pdo);
    }

    private static function ensureOphaalbevestigingColumns(PDO $pdo): void
    {
        $alterStatements = [
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS klantemail VARCHAR(191) NULL AFTER klantnaam',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS klanttelefoon VARCHAR(64) NULL AFTER klantemail',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS apparaatmerk VARCHAR(120) NULL AFTER merkmodel',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS apparaatmodel VARCHAR(191) NULL AFTER apparaatmerk',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS case_reference VARCHAR(64) NULL AFTER ophaalcode',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS case_id INT UNSIGNED NULL AFTER case_reference',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT "klaar"',
            'ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS opmerkingen TEXT NULL AFTER datumgereed'
        ];

        foreach ($alterStatements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                // Older MySQL versions do not support IF NOT EXISTS; ignore duplicate column errors.
                if (stripos($exception->getMessage(), 'Duplicate column name') === false) {
                    throw $exception;
                }
            }
        }

        try {
            $pdo->exec('ALTER TABLE ophaalbevestigingen ADD CONSTRAINT fk_ophaalbevestigingen_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            if (stripos($exception->getMessage(), 'Duplicate') === false) {
                if (stripos($exception->getMessage(), 'Cannot find symbol') === false) {
                    if (stripos($exception->getMessage(), 'already exists') === false) {
                        throw $exception;
                    }
                }
            }
        }
    }

    private static function ensureReparatieOnderzoekColumns(PDO $pdo): void
    {
        $statements = [
            'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_brand VARCHAR(120) NULL AFTER email',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_model VARCHAR(191) NULL AFTER device_brand',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_serial VARCHAR(120) NULL AFTER device_model',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_notes TEXT NULL AFTER device_serial',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS case_reference VARCHAR(64) NULL AFTER id'
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                if (stripos($exception->getMessage(), 'Duplicate column name') === false) {
                    throw $exception;
                }
            }
        }
    }

    private static function ensureDataRecoveryColumns(PDO $pdo): void
    {
        $statements = [
            'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS case_reference VARCHAR(64) NULL AFTER id',
            'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS device_brand VARCHAR(120) NULL AFTER email',
            'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS device_model VARCHAR(191) NULL AFTER device_brand',
            'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS device_serial VARCHAR(120) NULL AFTER device_model',
            'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS notes TEXT NULL AFTER signature'
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                if (stripos($exception->getMessage(), 'Duplicate column name') === false) {
                    throw $exception;
                }
            }
        }
    }

    private static function ensureDefaultUserExists(PDO $pdo): void
    {
        $defaultUsername = (string) Env::get('APP_ADMIN_USER', 'admin');
        $defaultPassword = (string) Env::get('APP_ADMIN_PASSWORD', 'changeme');
        $defaultRole = (string) Env::get('APP_ADMIN_ROLE', 'admin');

        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $statement->execute(['username' => $defaultUsername]);
        $exists = (int) $statement->fetchColumn() > 0;

        if ($exists) {
            return;
        }

        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (:username, :password_hash, :role)');
        $insert->execute([
            'username' => $defaultUsername,
            'password_hash' => password_hash($defaultPassword, PASSWORD_DEFAULT),
            'role' => $defaultRole,
        ]);
    }
}