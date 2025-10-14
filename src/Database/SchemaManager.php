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
        $driver = self::databaseDriver($pdo);
        $statements = $driver === 'pgsql'
            ? self::postgresBaseStatements()
            : self::mysqlBaseStatements();

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }

        self::ensureOphaalbevestigingenTable($pdo);
        self::ensureReparatieOnderzoekTable($pdo);
        self::ensureDataRecoveryTable($pdo);

        self::ensureOphaalbevestigingColumns($pdo);
        self::ensureReparatieOnderzoekColumns($pdo);
        self::ensureDataRecoveryColumns($pdo);
        self::ensureDefaultUserExists($pdo);
    }

    private static function mysqlBaseStatements(): array
    {
        return [
            <<<SQL
            CREATE TABLE IF NOT EXISTS customers (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS devices (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS cases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                device_id INT UNSIGNED NULL,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(64) NOT NULL DEFAULT 'open',
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS notes (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NOT NULL,
                customer_id INT UNSIGNED NOT NULL,
                author VARCHAR(120) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_notes_cases FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_notes_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(64) NOT NULL DEFAULT 'staff',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }

        private static function postgresBaseStatements(): array
    {
        return [
            <<<SQL
            CREATE TABLE IF NOT EXISTS customers (
                id SERIAL PRIMARY KEY,
                full_name VARCHAR(191) NOT NULL,
                email VARCHAR(191) NULL,
                phone VARCHAR(64) NULL,
                address VARCHAR(255) NULL,
                postal_code VARCHAR(32) NULL,
                city VARCHAR(120) NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                last_interaction_at TIMESTAMP WITHOUT TIME ZONE NULL
            )
            SQL,
            <<<SQL
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_customers_email ON customers(email)
            SQL,
            <<<SQL
            CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone)
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS devices (
                id SERIAL PRIMARY KEY,
                customer_id INTEGER NOT NULL,
                brand VARCHAR(120) NULL,
                model VARCHAR(191) NULL,
                serial_number VARCHAR(120) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uniq_device_customer_serial UNIQUE (customer_id, serial_number),
                CONSTRAINT fk_devices_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )
            SQL,
            <<<SQL
            CREATE INDEX IF NOT EXISTS idx_devices_customer ON devices(customer_id)
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS cases (
                id SERIAL PRIMARY KEY,
                customer_id INTEGER NOT NULL,
                device_id INTEGER NULL,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(64) NOT NULL DEFAULT 'open',
                reference_code VARCHAR(64) NULL,
                summary VARCHAR(255) NULL,
                details JSONB NULL,
                opened_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                closed_at TIMESTAMP WITHOUT TIME ZONE NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cases_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_cases_devices FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
            )
            SQL,
            <<<SQL
            CREATE INDEX IF NOT EXISTS idx_cases_customer ON cases(customer_id)
            SQL,
            <<<SQL
            CREATE INDEX IF NOT EXISTS idx_cases_reference ON cases(reference_code)
            SQL,
            <<<SQL
            CREATE INDEX IF NOT EXISTS idx_cases_status ON cases(status)
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS notes (
                id SERIAL PRIMARY KEY,
                case_id INTEGER NOT NULL,
                customer_id INTEGER NOT NULL,
                author VARCHAR(120) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_notes_cases FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_notes_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                username VARCHAR(120) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(64) NOT NULL DEFAULT 'staff',
                created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
        ];
    }

    private static function ensureOphaalbevestigingColumns(PDO $pdo): void
    {
        self::ensureOphaalbevestigingenTable($pdo);

        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $columns = [
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS klantemail VARCHAR(191)",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS klanttelefoon VARCHAR(64)",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS apparaatmerk VARCHAR(120)",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS apparaatmodel VARCHAR(191)",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS case_reference VARCHAR(64)",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS case_id INTEGER",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS status VARCHAR(32) NOT NULL DEFAULT 'klaar'",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS opmerkingen TEXT",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS pickup_signature TEXT",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS pickup_signed_at TIMESTAMP WITHOUT TIME ZONE"
            ];

            foreach ($columns as $sql) {
                $pdo->exec($sql);
            }

            $indexes = [
                'CREATE UNIQUE INDEX IF NOT EXISTS uniq_ophaalbevestigingen_ophaalcode ON ophaalbevestigingen(ophaalcode)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_case ON ophaalbevestigingen(case_id)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_case_reference ON ophaalbevestigingen(case_reference)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_datumgereed ON ophaalbevestigingen(datumgereed)'
            ];

            foreach ($indexes as $sql) {
                $pdo->exec($sql);
            }

            try {
                $pdo->exec('ALTER TABLE ophaalbevestigingen ADD CONSTRAINT fk_ophaalbevestigingen_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL');
            } catch (PDOException $exception) {
                 if (!self::isDuplicateError($exception)) {
                    throw $exception;
                }
            }
            return;
        }

        $columns = [
            "ALTER TABLE ophaalbevestigingen ADD COLUMN klantemail VARCHAR(191) NULL AFTER klantnaam",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN klanttelefoon VARCHAR(64) NULL AFTER klantemail",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN apparaatmerk VARCHAR(120) NULL AFTER merkmodel",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN apparaatmodel VARCHAR(191) NULL AFTER apparaatmerk",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN case_reference VARCHAR(64) NULL AFTER ophaalcode",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN case_id INT UNSIGNED NULL AFTER case_reference",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'klaar'",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN opmerkingen TEXT NULL AFTER datumgereed",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN pickup_signature LONGTEXT NULL AFTER opmerkingen",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN pickup_signed_at DATETIME NULL AFTER pickup_signature"
        ];

        foreach ($columns as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate column']);
        }

        try {
            $pdo->exec('ALTER TABLE ophaalbevestigingen ADD CONSTRAINT fk_ophaalbevestigingen_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL');
        } catch (PDOException $exception) {
            if (!self::isDuplicateError($exception)) {
                throw $exception;
            }
        }
         $indexes = [
            'ALTER TABLE ophaalbevestigingen ADD UNIQUE INDEX uniq_ophaalbevestigingen_ophaalcode (ophaalcode)',
            'ALTER TABLE ophaalbevestigingen ADD INDEX idx_ophaalbevestigingen_datumgereed (datumgereed)',
            'ALTER TABLE ophaalbevestigingen ADD INDEX idx_ophaalbevestigingen_case_reference (case_reference)'
        ];

        foreach ($indexes as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate', 'already exists']);
        }
    }

    private static function ensureReparatieOnderzoekColumns(PDO $pdo): void
    {
        self::ensureReparatieOnderzoekTable($pdo);

        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $statements = [
                'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_brand VARCHAR(120)',
                'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_model VARCHAR(191)',
                'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_serial VARCHAR(120)',
                'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS device_notes TEXT',
                'ALTER TABLE reparatie_onderzoek ADD COLUMN IF NOT EXISTS case_reference VARCHAR(64)'
            ];

            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }

            return;
        }

        $statements = [
            'ALTER TABLE reparatie_onderzoek ADD COLUMN device_brand VARCHAR(120) NULL AFTER email',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN device_model VARCHAR(191) NULL AFTER device_brand',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN device_serial VARCHAR(120) NULL AFTER device_model',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN device_notes TEXT NULL AFTER device_serial',
            'ALTER TABLE reparatie_onderzoek ADD COLUMN case_reference VARCHAR(64) NULL AFTER id'
        ];

        foreach ($statements as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate column']);
        }
    }

    private static function ensureDataRecoveryColumns(PDO $pdo): void
    {
        self::ensureDataRecoveryTable($pdo);

        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $statements = [
                'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS case_reference VARCHAR(64)',
                'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS device_brand VARCHAR(120)',
                'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS device_model VARCHAR(191)',
                'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS device_serial VARCHAR(120)',
                'ALTER TABLE data_recovery ADD COLUMN IF NOT EXISTS notes TEXT'
            ];

            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }

            return;
        }


        $statements = [
            'ALTER TABLE data_recovery ADD COLUMN case_reference VARCHAR(64) NULL AFTER id',
            'ALTER TABLE data_recovery ADD COLUMN device_brand VARCHAR(120) NULL AFTER email',
            'ALTER TABLE data_recovery ADD COLUMN device_model VARCHAR(191) NULL AFTER device_brand',
            'ALTER TABLE data_recovery ADD COLUMN device_serial VARCHAR(120) NULL AFTER device_model',
            'ALTER TABLE data_recovery ADD COLUMN notes TEXT NULL AFTER signature'
        ];

        foreach ($statements as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate column']);
        }
    }

    private static function ensureOphaalbevestigingenTable(PDO $pdo): void
    {
        if (self::databaseDriver($pdo) === 'pgsql') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS ophaalbevestigingen (
                    id SERIAL PRIMARY KEY,
                    klantnaam VARCHAR(191) NOT NULL,
                    klantemail VARCHAR(191) NULL,
                    klanttelefoon VARCHAR(64) NULL,
                    merkmodel VARCHAR(191) NOT NULL,
                    apparaatmerk VARCHAR(120) NULL,
                    apparaatmodel VARCHAR(191) NULL,
                    ophaalcode VARCHAR(64) NOT NULL,
                    case_reference VARCHAR(64) NULL,
                    case_id INTEGER NULL,
                    datumgereed DATE NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'klaar',
                    opmerkingen TEXT NULL,
                    pickup_signature TEXT NULL,
                    pickup_signed_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_ophaalbevestigingen_ophaalcode UNIQUE (ophaalcode),
                    CONSTRAINT fk_ophaalbevestigingen_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
                )
            SQL;

            $pdo->exec($sql);

            $indexes = [
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_case ON ophaalbevestigingen(case_id)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_case_reference ON ophaalbevestigingen(case_reference)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_datumgereed ON ophaalbevestigingen(datumgereed)'
            ];

            foreach ($indexes as $statement) {
                $pdo->exec($statement);
            }

            return;
        }
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS ophaalbevestigingen (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                klantnaam VARCHAR(191) NOT NULL,
                klantemail VARCHAR(191) NULL,
                klanttelefoon VARCHAR(64) NULL,
                merkmodel VARCHAR(191) NOT NULL,
                apparaatmerk VARCHAR(120) NULL,
                apparaatmodel VARCHAR(191) NULL,
                ophaalcode VARCHAR(64) NOT NULL,
                case_reference VARCHAR(64) NULL,
                case_id INT UNSIGNED NULL,
                datumgereed DATE NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'klaar',
                opmerkingen TEXT NULL,
                pickup_signature LONGTEXT NULL,
                pickup_signed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ophaalbevestigingen_ophaalcode (ophaalcode),
                INDEX idx_ophaalbevestigingen_case (case_id),
                INDEX idx_ophaalbevestigingen_case_reference (case_reference),
                INDEX idx_ophaalbevestigingen_datumgereed (datumgereed),
                CONSTRAINT fk_ophaalbevestigingen_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $pdo->exec($sql);
    }

    private static function ensureReparatieOnderzoekTable(PDO $pdo): void
    {
        if (self::databaseDriver($pdo) === 'pgsql') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS reparatie_onderzoek (
                    id SERIAL PRIMARY KEY,
                    fullname VARCHAR(191) NOT NULL,
                    address VARCHAR(255) NOT NULL,
                    phone VARCHAR(64) NOT NULL,
                    email VARCHAR(191) NOT NULL,
                    repair_consent_100 BOOLEAN NOT NULL DEFAULT FALSE,
                    repair_consent_notify BOOLEAN NOT NULL DEFAULT FALSE,
                    repair_consent_custom BOOLEAN NOT NULL DEFAULT FALSE,
                    custom_amount VARCHAR(32) NULL,
                    signature_name VARCHAR(191) NOT NULL,
                    signature_place VARCHAR(191) NOT NULL,
                    signature_date DATE NOT NULL,
                    signature TEXT NULL,
                    device_brand VARCHAR(120) NULL,
                    device_model VARCHAR(191) NULL,
                    device_serial VARCHAR(120) NULL,
                    device_notes TEXT NULL,
                    case_reference VARCHAR(64) NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
                )
            SQL;

            $pdo->exec($sql);

            return;
        }
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS reparatie_onderzoek (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fullname VARCHAR(191) NOT NULL,
                address VARCHAR(255) NOT NULL,
                phone VARCHAR(64) NOT NULL,
                email VARCHAR(191) NOT NULL,
                repair_consent_100 TINYINT(1) NOT NULL DEFAULT 0,
                repair_consent_notify TINYINT(1) NOT NULL DEFAULT 0,
                repair_consent_custom TINYINT(1) NOT NULL DEFAULT 0,
                custom_amount VARCHAR(32) NULL,
                signature_name VARCHAR(191) NOT NULL,
                signature_place VARCHAR(191) NOT NULL,
                signature_date DATE NOT NULL,
                signature TEXT NULL,
                device_brand VARCHAR(120) NULL,
                device_model VARCHAR(191) NULL,
                device_serial VARCHAR(120) NULL,
                device_notes TEXT NULL,
                case_reference VARCHAR(64) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $pdo->exec($sql);
    }

    private static function ensureDataRecoveryTable(PDO $pdo): void
    {
        if (self::databaseDriver($pdo) === 'pgsql') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS data_recovery (
                    id SERIAL PRIMARY KEY,
                    fullname VARCHAR(191) NOT NULL,
                    address VARCHAR(255) NOT NULL,
                    postcode VARCHAR(32) NOT NULL,
                    phone VARCHAR(64) NOT NULL,
                    email VARCHAR(191) NOT NULL,
                    signature_date DATE NOT NULL,
                    signature TEXT NULL,
                    case_reference VARCHAR(64) NOT NULL,
                    device_brand VARCHAR(120) NULL,
                    device_model VARCHAR(191) NULL,
                    device_serial VARCHAR(120) NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_data_recovery_case_reference UNIQUE (case_reference)
                )
            SQL;

            $pdo->exec($sql);

            return;
        }
        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS data_recovery (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fullname VARCHAR(191) NOT NULL,
                address VARCHAR(255) NOT NULL,
                postcode VARCHAR(32) NOT NULL,
                phone VARCHAR(64) NOT NULL,
                email VARCHAR(191) NOT NULL,
                signature_date DATE NOT NULL,
                signature TEXT NULL,
                case_reference VARCHAR(64) NOT NULL,
                device_brand VARCHAR(120) NULL,
                device_model VARCHAR(191) NULL,
                device_serial VARCHAR(120) NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_data_recovery_case_reference (case_reference)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $pdo->exec($sql);
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
private static function databaseDriver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    private static function executeIgnoringDuplicates(PDO $pdo, string $sql, array $keywords): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $exception) {
            if (!self::containsKeyword($exception, $keywords)) {
                throw $exception;
            }
        }
    }

    private static function isDuplicateError(PDOException $exception): bool
    {
        return self::containsKeyword($exception, ['duplicate', 'already exists']);
    }

    private static function containsKeyword(PDOException $exception, array $keywords): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ($keywords as $keyword) {
            if (str_contains($message, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}