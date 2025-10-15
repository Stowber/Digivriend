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
        $statements = match ($driver) {
            'pgsql' => self::postgresBaseStatements(),
            'sqlite' => self::sqliteBaseStatements(),
            default => self::mysqlBaseStatements(),
        };

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }

        self::ensureOphaalbevestigingenTable($pdo);
        self::ensureReparatieOnderzoekTable($pdo);
        self::ensureDataRecoveryTable($pdo);
        self::ensureNotificationTable($pdo);
        self::ensureCaseChecklistsTables($pdo);
        self::ensureCaseAuditLogTable($pdo);
        self::ensureDocumentsTable($pdo);
        self::ensureDeviceEnhancements($pdo);

        self::ensureOphaalbevestigingColumns($pdo);
        self::ensureReparatieOnderzoekColumns($pdo);
        self::ensureDataRecoveryColumns($pdo);
        self::ensureOphaalbevestigingEnhancements($pdo);
        self::ensureDefaultUserExists($pdo);
    }

    private static function ensureDeviceEnhancements(PDO $pdo): void
    {
        self::ensureDeviceBarcodeColumn($pdo);
        self::ensureDevicePhotosTable($pdo);
        self::ensureRepairEventsTable($pdo);
        self::populateMissingDeviceBarcodes($pdo);
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

        private static function sqliteBaseStatements(): array
    {
        return [
            <<<SQL
            CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                full_name TEXT NOT NULL,
                email TEXT NULL,
                phone TEXT NULL,
                address TEXT NULL,
                postal_code TEXT NULL,
                city TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                last_interaction_at TEXT NULL
            )
            SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_email ON customers(email)',
            'CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone)',
            <<<SQL
            CREATE TABLE IF NOT EXISTS devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                brand TEXT NULL,
                model TEXT NULL,
                serial_number TEXT NULL,
                notes TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_devices_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT uniq_device_customer_serial UNIQUE (customer_id, serial_number)
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_devices_customer ON devices(customer_id)',
            <<<SQL
            CREATE TABLE IF NOT EXISTS cases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                device_id INTEGER NULL,
                type TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'open',
                reference_code TEXT NULL,
                summary TEXT NULL,
                details TEXT NULL,
                opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
                closed_at TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_cases_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_cases_devices FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
            )
            SQL,
            'CREATE INDEX IF NOT EXISTS idx_cases_customer ON cases(customer_id)',
            'CREATE INDEX IF NOT EXISTS idx_cases_reference ON cases(reference_code)',
            'CREATE INDEX IF NOT EXISTS idx_cases_status ON cases(status)',
            <<<SQL
            CREATE TABLE IF NOT EXISTS notes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_id INTEGER NOT NULL,
                customer_id INTEGER NOT NULL,
                author TEXT NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_notes_cases FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_notes_customers FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'staff',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            )
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
        if ($driver === 'sqlite') {
            $columns = [
                ['klantemail', 'TEXT'],
                ['klanttelefoon', 'TEXT'],
                ['apparaatmerk', 'TEXT'],
                ['apparaatmodel', 'TEXT'],
                ['case_reference', 'TEXT'],
                ['case_id', 'INTEGER'],
                ['created_at', 'TEXT DEFAULT CURRENT_TIMESTAMP'],
                ['updated_at', 'TEXT DEFAULT CURRENT_TIMESTAMP'],
                ['status', "TEXT NOT NULL DEFAULT 'klaar'"],
                ['opmerkingen', 'TEXT'],
                ['pickup_signature', 'TEXT'],
                ['pickup_signed_at', 'TEXT'],
            ];

            foreach ($columns as [$name, $definition]) {
                self::addSqliteColumnIfMissing($pdo, 'ophaalbevestigingen', $name, $definition);
            }

            $indexes = [
                'CREATE UNIQUE INDEX IF NOT EXISTS idx_ophaalbevestigingen_ophaalcode ON ophaalbevestigingen(ophaalcode)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_case ON ophaalbevestigingen(case_id)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_case_reference ON ophaalbevestigingen(case_reference)',
                'CREATE INDEX IF NOT EXISTS idx_ophaalbevestigingen_datumgereed ON ophaalbevestigingen(datumgereed)',
            ];

            foreach ($indexes as $sql) {
                $pdo->exec($sql);
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
        if ($driver === 'sqlite') {
            $columns = [
                ['device_brand', 'TEXT'],
                ['device_model', 'TEXT'],
                ['device_serial', 'TEXT'],
                ['device_notes', 'TEXT'],
                ['case_reference', 'TEXT'],
            ];

            foreach ($columns as [$name, $definition]) {
                self::addSqliteColumnIfMissing($pdo, 'reparatie_onderzoek', $name, $definition);
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
        if ($driver === 'sqlite') {
            $columns = [
                ['case_reference', 'TEXT'],
                ['device_brand', 'TEXT'],
                ['device_model', 'TEXT'],
                ['device_serial', 'TEXT'],
                ['notes', 'TEXT'],
            ];

            foreach ($columns as [$name, $definition]) {
                self::addSqliteColumnIfMissing($pdo, 'data_recovery', $name, $definition);
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
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
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
        if ($driver === 'sqlite') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS ophaalbevestigingen (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    klantnaam TEXT NOT NULL,
                    klantemail TEXT NULL,
                    klanttelefoon TEXT NULL,
                    merkmodel TEXT NOT NULL,
                    apparaatmerk TEXT NULL,
                    apparaatmodel TEXT NULL,
                    ophaalcode TEXT NOT NULL,
                    case_reference TEXT NULL,
                    case_id INTEGER NULL,
                    datumgereed TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'klaar',
                    opmerkingen TEXT NULL,
                    pickup_signature TEXT NULL,
                    pickup_signed_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_ophaalbevestigingen_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    CONSTRAINT uniq_ophaalbevestigingen_ophaalcode UNIQUE (ophaalcode)
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
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
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
        if ($driver === 'sqlite') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS reparatie_onderzoek (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    fullname TEXT NOT NULL,
                    address TEXT NOT NULL,
                    phone TEXT NOT NULL,
                    email TEXT NOT NULL,
                    repair_consent_100 INTEGER NOT NULL DEFAULT 0,
                    repair_consent_notify INTEGER NOT NULL DEFAULT 0,
                    repair_consent_custom INTEGER NOT NULL DEFAULT 0,
                    custom_amount TEXT NULL,
                    signature_name TEXT NOT NULL,
                    signature_place TEXT NOT NULL,
                    signature_date TEXT NOT NULL,
                    signature TEXT NULL,
                    device_brand TEXT NULL,
                    device_model TEXT NULL,
                    device_serial TEXT NULL,
                    device_notes TEXT NULL,
                    case_reference TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
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
        if ($driver === 'sqlite') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS data_recovery (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    fullname TEXT NOT NULL,
                    address TEXT NOT NULL,
                    postcode TEXT NOT NULL,
                    phone TEXT NOT NULL,
                    email TEXT NOT NULL,
                    signature_date TEXT NOT NULL,
                    signature TEXT NULL,
                    case_reference TEXT NOT NULL,
                    device_brand TEXT NULL,
                    device_model TEXT NULL,
                    device_serial TEXT NULL,
                    notes TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
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

    private static function ensureNotificationTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS notifications (
                    id SERIAL PRIMARY KEY,
                    case_id INT NULL,
                    customer_id INT NULL,
                    channel VARCHAR(32) NOT NULL,
                    recipient VARCHAR(191) NOT NULL,
                    subject VARCHAR(191) NULL,
                    body TEXT NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'queued',
                    error TEXT NULL,
                    sent_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_notifications_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
                )
            SQL;

            $pdo->exec($sql);

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications(status)');

            return;
        }

        if ($driver === 'sqlite') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NULL,
                    customer_id INTEGER NULL,
                    channel TEXT NOT NULL,
                    recipient TEXT NOT NULL,
                    subject TEXT NULL,
                    body TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'queued',
                    error TEXT NULL,
                    sent_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            SQL;

            $pdo->exec($sql);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_notifications_status ON notifications(status)');

            return;
        }

        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NULL,
                customer_id INT UNSIGNED NULL,
                channel VARCHAR(32) NOT NULL,
                recipient VARCHAR(191) NOT NULL,
                subject VARCHAR(191) NULL,
                body TEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'queued',
                error TEXT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_notifications_status (status),
                CONSTRAINT fk_notifications_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $pdo->exec($sql);
    }

    private static function ensureCaseChecklistsTables(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS case_checklists (
                    id SERIAL PRIMARY KEY,
                    case_id INT NOT NULL,
                    title VARCHAR(191) NOT NULL,
                    assigned_to VARCHAR(120) NULL,
                    due_at DATE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_case_checklists_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
                )
            SQL);

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS case_checklist_items (
                    id SERIAL PRIMARY KEY,
                    checklist_id INT NOT NULL,
                    description VARCHAR(255) NOT NULL,
                    is_completed BOOLEAN NOT NULL DEFAULT FALSE,
                    completed_by VARCHAR(120) NULL,
                    completed_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_checklist_items_checklist FOREIGN KEY (checklist_id) REFERENCES case_checklists(id) ON DELETE CASCADE
                )
            SQL);

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS case_checklists (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    assigned_to TEXT NULL,
                    due_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            SQL);

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS case_checklist_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    checklist_id INTEGER NOT NULL,
                    description TEXT NOT NULL,
                    is_completed INTEGER NOT NULL DEFAULT 0,
                    completed_by TEXT NULL,
                    completed_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            SQL);

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checklist_items_checklist ON case_checklist_items(checklist_id)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS case_checklists (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NOT NULL,
                title VARCHAR(191) NOT NULL,
                assigned_to VARCHAR(120) NULL,
                due_at DATE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_case_checklists_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS case_checklist_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                checklist_id INT UNSIGNED NOT NULL,
                description VARCHAR(255) NOT NULL,
                is_completed TINYINT(1) NOT NULL DEFAULT 0,
                completed_by VARCHAR(120) NULL,
                completed_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_checklist_items_checklist (checklist_id),
                CONSTRAINT fk_case_checklist_items_checklist FOREIGN KEY (checklist_id) REFERENCES case_checklists(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureCaseAuditLogTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS case_audit_logs (
                    id SERIAL PRIMARY KEY,
                    case_id INT NOT NULL,
                    user_id INT NULL,
                    username VARCHAR(120) NOT NULL,
                    action VARCHAR(191) NOT NULL,
                    context JSON NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_case_audit_logs_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                    CONSTRAINT fk_case_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                )
            SQL;

            $pdo->exec($sql);

            return;
        }

        if ($driver === 'sqlite') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS case_audit_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NOT NULL,
                    user_id INTEGER NULL,
                    username TEXT NOT NULL,
                    action TEXT NOT NULL,
                    context TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            SQL;

            $pdo->exec($sql);

            return;
        }

        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS case_audit_logs (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                username VARCHAR(120) NOT NULL,
                action VARCHAR(191) NOT NULL,
                context JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_case_audit_logs_case (case_id),
                CONSTRAINT fk_case_audit_logs_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $pdo->exec($sql);
    }

    private static function ensureDocumentsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS documents (
                    id SERIAL PRIMARY KEY,
                    case_id INT NULL,
                    type VARCHAR(64) NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    metadata JSON NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_documents_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
                )
            SQL;

            $pdo->exec($sql);

            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(type)');

            return;
        }

        if ($driver === 'sqlite') {
            $sql = <<<SQL
                CREATE TABLE IF NOT EXISTS documents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NULL,
                    type TEXT NOT NULL,
                    file_path TEXT NOT NULL,
                    metadata TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            SQL;

            $pdo->exec($sql);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_documents_type ON documents(type)');

            return;
        }

        $sql = <<<SQL
            CREATE TABLE IF NOT EXISTS documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NULL,
                type VARCHAR(64) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_documents_type (type),
                CONSTRAINT fk_documents_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL;

        $pdo->exec($sql);
    }

    private static function ensureOphaalbevestigingEnhancements(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $statements = [
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS notified_ready_at TIMESTAMP WITHOUT TIME ZONE",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS notified_collected_at TIMESTAMP WITHOUT TIME ZONE",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS pickup_scheduled_at TIMESTAMP WITHOUT TIME ZONE",
                "ALTER TABLE ophaalbevestigingen ADD COLUMN IF NOT EXISTS pickup_window VARCHAR(120)"
            ];

            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }

            return;
        }

        if ($driver === 'sqlite') {
            $columns = [
                ['notified_ready_at', 'TEXT'],
                ['notified_collected_at', 'TEXT'],
                ['pickup_scheduled_at', 'TEXT'],
                ['pickup_window', 'TEXT'],
            ];

            foreach ($columns as [$name, $definition]) {
                self::addSqliteColumnIfMissing($pdo, 'ophaalbevestigingen', $name, $definition);
            }

            return;
        }

        $statements = [
            "ALTER TABLE ophaalbevestigingen ADD COLUMN notified_ready_at DATETIME NULL AFTER status",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN notified_collected_at DATETIME NULL AFTER notified_ready_at",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN pickup_scheduled_at DATETIME NULL AFTER notified_collected_at",
            "ALTER TABLE ophaalbevestigingen ADD COLUMN pickup_window VARCHAR(120) NULL AFTER pickup_scheduled_at"
        ];

        foreach ($statements as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate column', 'already exists']);
        }
    }

    private static function ensureDeviceBarcodeColumn(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE devices ADD COLUMN IF NOT EXISTS barcode VARCHAR(64)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_devices_barcode ON devices(barcode)');
            return;
        }

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'devices', 'barcode', 'TEXT');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_devices_barcode ON devices(barcode)');
            return;
        }

        $statements = [
            "ALTER TABLE devices ADD COLUMN barcode VARCHAR(64) NULL AFTER serial_number",
            'ALTER TABLE devices ADD UNIQUE INDEX uniq_devices_barcode (barcode)',
        ];

        foreach ($statements as $index => $sql) {
            $keywords = $index === 0 ? ['duplicate column', 'already exists'] : ['duplicate', 'already exists'];
            self::executeIgnoringDuplicates($pdo, $sql, $keywords);
        }
    }

    private static function ensureDevicePhotosTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS device_photos (
                    id SERIAL PRIMARY KEY,
                    device_id INTEGER NOT NULL,
                    orientation VARCHAR(32) NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    captured_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_device_photos_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_photos_device ON device_photos(device_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_photos_orientation ON device_photos(device_id, orientation)');
            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS device_photos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id INTEGER NOT NULL,
                    orientation TEXT NOT NULL,
                    file_path TEXT NOT NULL,
                    captured_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_device_photos_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_photos_device ON device_photos(device_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_photos_orientation ON device_photos(device_id, orientation)');
            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS device_photos (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id INT UNSIGNED NOT NULL,
                orientation VARCHAR(32) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                captured_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_device_photos_device (device_id),
                INDEX idx_device_photos_orientation (device_id, orientation),
                CONSTRAINT fk_device_photos_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureRepairEventsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS repair_events (
                    id SERIAL PRIMARY KEY,
                    case_id INTEGER NULL,
                    device_id INTEGER NOT NULL,
                    event_type VARCHAR(64) NOT NULL,
                    description TEXT NOT NULL,
                    performed_by VARCHAR(120) NOT NULL,
                    performed_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    metadata JSONB NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_repair_events_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    CONSTRAINT fk_repair_events_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_repair_events_device ON repair_events(device_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_repair_events_case ON repair_events(case_id)');
            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS repair_events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NULL,
                    device_id INTEGER NOT NULL,
                    event_type TEXT NOT NULL,
                    description TEXT NOT NULL,
                    performed_by TEXT NOT NULL,
                    performed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    metadata TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_repair_events_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    CONSTRAINT fk_repair_events_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_repair_events_device ON repair_events(device_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_repair_events_case ON repair_events(case_id)');
            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS repair_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NULL,
                device_id INT UNSIGNED NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                description TEXT NOT NULL,
                performed_by VARCHAR(120) NOT NULL,
                performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                metadata JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_repair_events_device (device_id),
                INDEX idx_repair_events_case (case_id),
                CONSTRAINT fk_repair_events_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                CONSTRAINT fk_repair_events_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function populateMissingDeviceBarcodes(PDO $pdo): void
    {
        $statement = $pdo->query("SELECT id FROM devices WHERE barcode IS NULL OR barcode = ''");

        if ($statement === false) {
            return;
        }

        $update = $pdo->prepare('UPDATE devices SET barcode = :barcode WHERE id = :id');

        while (($deviceId = $statement->fetchColumn()) !== false) {
            $barcode = self::generateUniqueDeviceBarcode($pdo);
            $update->execute([
                'id' => (int) $deviceId,
                'barcode' => $barcode,
            ]);
        }
    }

    private static function generateUniqueDeviceBarcode(PDO $pdo): string
    {
        do {
            $candidate = self::generateDeviceBarcodeValue();
            $check = $pdo->prepare('SELECT COUNT(*) FROM devices WHERE barcode = :barcode');
            $check->execute(['barcode' => $candidate]);
            $exists = (int) $check->fetchColumn() > 0;
        } while ($exists);

        return $candidate;
    }

    private static function generateDeviceBarcodeValue(): string
    {
        $datePart = (new \DateTimeImmutable())->format('ymd');
        $random = strtoupper(bin2hex(random_bytes(3)));

        return 'DV' . $datePart . $random;
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
    
    private static function addSqliteColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (self::sqliteColumnExists($pdo, $table, $column)) {
            return;
        }

        $tableName = self::quoteSqliteIdentifier($table);
        $columnName = self::quoteSqliteIdentifier($column);

        $pdo->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $tableName,
            $columnName,
            $definition
        ));
    }

    private static function sqliteColumnExists(PDO $pdo, string $table, string $column): bool
    {
        $tableName = self::quoteSqliteIdentifier($table);
        $statement = $pdo->query(sprintf('PRAGMA table_info(%s)', $tableName));

        if ($statement === false) {
            return false;
        }

        while ($info = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (isset($info['name']) && strtolower((string) $info['name']) === strtolower($column)) {
                return true;
            }
        }

        return false;
    }

    private static function quoteSqliteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
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