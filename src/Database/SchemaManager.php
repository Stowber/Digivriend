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

        if ($driver === 'sqlite') {
            self::ensureSqliteIndexes($pdo);
        }

        self::ensureOphaalbevestigingenTable($pdo);
        self::ensureReparatieOnderzoekTable($pdo);
        self::ensureDataRecoveryTable($pdo);
        self::ensureNotificationTable($pdo);
        self::ensureCaseChecklistsTables($pdo);
        self::ensureCaseAuditLogTable($pdo);
        self::ensureDocumentsTable($pdo);
        self::ensureDeviceEnhancements($pdo);
        self::ensureWarehouseTables($pdo);
        self::ensureEmployeeTables($pdo);
        self::ensureCaseEnhancements($pdo);
        self::ensureCalendarTables($pdo);

        self::ensureOphaalbevestigingColumns($pdo);
        self::ensureReparatieOnderzoekColumns($pdo);
        self::ensureDataRecoveryColumns($pdo);
        self::ensureOphaalbevestigingEnhancements($pdo);
        self::ensureDefaultUserExists($pdo);
    }

    private static function ensurePcBuildEnhancements(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'customer_id', 'INTEGER NULL');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'assigned_employee', 'TEXT NULL');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'current_step', "TEXT NOT NULL DEFAULT 'information'");
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'planning_payload', 'TEXT NULL');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'planning_total_cents', 'INTEGER NOT NULL DEFAULT 0');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'planning_currency', "TEXT NOT NULL DEFAULT 'PLN'");
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'assembly_payload', 'TEXT NULL');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'release_payload', 'TEXT NULL');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'approved_at', 'TEXT NULL');
            self::addSqliteColumnIfMissing($pdo, 'pc_builds', 'approved_by', 'TEXT NULL');

            self::createSqliteIndexIfColumnsExist(
                $pdo,
                'pc_builds',
                ['customer_id'],
                'CREATE INDEX IF NOT EXISTS idx_pc_builds_customer ON pc_builds(customer_id)'
            );
            self::createSqliteIndexIfColumnsExist(
                $pdo,
                'pc_builds',
                ['current_step'],
                'CREATE INDEX IF NOT EXISTS idx_pc_builds_step ON pc_builds(current_step)'
            );

            return;
        }

        if ($driver === 'pgsql') {
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN customer_id INT NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN assigned_employee VARCHAR(191) NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ADD COLUMN current_step VARCHAR(32) NOT NULL DEFAULT 'information'", ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN planning_payload JSONB NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN planning_total_cents INT NOT NULL DEFAULT 0', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ADD COLUMN planning_currency VARCHAR(16) NOT NULL DEFAULT 'PLN'", ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN assembly_payload JSONB NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN release_payload JSONB NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN approved_at TIMESTAMP NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN approved_by VARCHAR(191) NULL', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ALTER COLUMN status SET DEFAULT 'draft'", ['duplicate', 'already exists']);
            self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ALTER COLUMN current_step SET DEFAULT 'information'", ['duplicate', 'already exists']);

            self::executeIgnoringDuplicates($pdo, 'CREATE INDEX idx_pc_builds_customer ON pc_builds(customer_id)', ['already exists', 'duplicate']);
            self::executeIgnoringDuplicates($pdo, 'CREATE INDEX idx_pc_builds_step ON pc_builds(current_step)', ['already exists', 'duplicate']);

            return;
        }

        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN customer_id INT UNSIGNED NULL AFTER case_profile_id', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN assigned_employee VARCHAR(191) NULL AFTER customer_id', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ADD COLUMN current_step VARCHAR(32) NOT NULL DEFAULT 'information' AFTER status", ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN planning_payload JSON NULL AFTER summary', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN planning_total_cents INT NOT NULL DEFAULT 0 AFTER planning_payload', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ADD COLUMN planning_currency VARCHAR(16) NOT NULL DEFAULT 'PLN' AFTER planning_total_cents", ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN assembly_payload JSON NULL AFTER planning_currency', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN release_payload JSON NULL AFTER assembly_payload', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER release_payload', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE pc_builds ADD COLUMN approved_by VARCHAR(191) NULL AFTER approved_at', ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ALTER COLUMN status SET DEFAULT 'draft'", ['duplicate', 'already exists']);
        self::executeIgnoringDuplicates($pdo, "ALTER TABLE pc_builds ALTER COLUMN current_step SET DEFAULT 'information'", ['duplicate', 'already exists']);

        self::executeIgnoringDuplicates($pdo, 'CREATE INDEX idx_pc_builds_customer ON pc_builds(customer_id)', ['already exists', 'duplicate']);
        self::executeIgnoringDuplicates($pdo, 'CREATE INDEX idx_pc_builds_step ON pc_builds(current_step)', ['already exists', 'duplicate']);
    }

    private static function ensureDeviceEnhancements(PDO $pdo): void
    {
        self::ensureDeviceBarcodeColumn($pdo);
        self::ensureDeviceTypeColumn($pdo);
        self::ensureDevicePhotosTable($pdo);
        self::ensureRepairEventsTable($pdo);
        self::ensureDeviceComponentsTable($pdo);
        self::ensureDeviceComponentEnhancements($pdo);
        self::populateMissingDeviceBarcodes($pdo);
    }

    private static function ensureWarehouseItemsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS warehouse_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    reference_code TEXT NOT NULL,
                    name TEXT NOT NULL,
                    category TEXT NULL,
                    location TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'received',
                    quantity INTEGER NOT NULL DEFAULT 0,
                    reserved_quantity INTEGER NOT NULL DEFAULT 0,
                    unit_price_cents INTEGER NOT NULL DEFAULT 0,
                    case_id INTEGER NULL,
                    device_id INTEGER NULL,
                    barcode TEXT NULL,
                    notes TEXT NULL,
                    received_at TEXT NULL,
                    reserved_at TEXT NULL,
                    ready_at TEXT NULL,
                    completed_at TEXT NULL,
                    last_movement_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_warehouse_reference ON warehouse_items(reference_code)');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_warehouse_barcode ON warehouse_items(barcode)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_warehouse_status ON warehouse_items(status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_warehouse_case ON warehouse_items(case_id)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS warehouse_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reference_code VARCHAR(64) NOT NULL,
                name VARCHAR(191) NOT NULL,
                category VARCHAR(120) NULL,
                location VARCHAR(120) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'received',
                quantity INT NOT NULL DEFAULT 0,
                reserved_quantity INT NOT NULL DEFAULT 0,
                unit_price_cents INT NOT NULL DEFAULT 0,
                case_id INT UNSIGNED NULL,
                device_id INT UNSIGNED NULL,
                barcode VARCHAR(64) NULL,
                notes TEXT NULL,
                received_at TIMESTAMP NULL DEFAULT NULL,
                reserved_at TIMESTAMP NULL DEFAULT NULL,
                ready_at TIMESTAMP NULL DEFAULT NULL,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                last_movement_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_warehouse_reference (reference_code),
                UNIQUE KEY uniq_warehouse_barcode (barcode),
                INDEX idx_warehouse_status (status),
                INDEX idx_warehouse_case (case_id),
                CONSTRAINT fk_warehouse_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                CONSTRAINT fk_warehouse_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureWarehouseMovementsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS warehouse_movements (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    item_id INTEGER NOT NULL,
                    case_id INTEGER NULL,
                    movement_type TEXT NOT NULL,
                    quantity INTEGER NOT NULL DEFAULT 0,
                    notes TEXT NULL,
                    performed_by TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_movements_item ON warehouse_movements(item_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_movements_case ON warehouse_movements(case_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_movements_type ON warehouse_movements(movement_type)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS warehouse_movements (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id INT UNSIGNED NOT NULL,
                case_id INT UNSIGNED NULL,
                movement_type VARCHAR(32) NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                performed_by VARCHAR(120) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_movements_item (item_id),
                INDEX idx_movements_case (case_id),
                INDEX idx_movements_type (movement_type),
                CONSTRAINT fk_movements_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_movements_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureWarehouseIndexes(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            self::createSqliteIndexIfColumnsExist(
                $pdo,
                'warehouse_items',
                ['last_movement_at'],
                'CREATE INDEX IF NOT EXISTS idx_warehouse_last_movement ON warehouse_items(last_movement_at)'
            );

            return;
        }

        try {
            $pdo->exec('CREATE INDEX idx_warehouse_last_movement ON warehouse_items(last_movement_at)');
        } catch (PDOException $exception) {
            if (!str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                throw $exception;
            }
        }
    }

    private static function ensureWarehouseTables(PDO $pdo): void
    {
        self::ensureWarehouseItemsTable($pdo);
        self::ensureWarehouseMovementsTable($pdo);
        self::ensureWarehouseIndexes($pdo);
        self::ensureHardwareProfilesTable($pdo);
        self::ensureWarehouseItemProfilesTable($pdo);
        self::ensurePcBuildTables($pdo);
        self::ensureWarehouseEnhancements($pdo);
    }

    private static function ensureWarehouseEnhancements(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'warehouse_items', 'unit_price_cents', 'INTEGER NOT NULL DEFAULT 0');

            return;
        }

        if ($driver === 'pgsql') {
            self::executeIgnoringDuplicates($pdo, 'ALTER TABLE warehouse_items ADD COLUMN unit_price_cents INT NOT NULL DEFAULT 0', ['already exists', 'duplicate']);

            return;
        }

        self::executeIgnoringDuplicates($pdo, 'ALTER TABLE warehouse_items ADD COLUMN unit_price_cents INT NOT NULL DEFAULT 0 AFTER reserved_quantity', ['duplicate', 'already exists']);
    }

    private static function ensureHardwareProfilesTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS hardware_profiles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    manufacturer TEXT NOT NULL,
                    model TEXT NOT NULL,
                    description TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(type, manufacturer, model)
                )
            SQL);

            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS hardware_profiles (
                    id SERIAL PRIMARY KEY,
                    type VARCHAR(64) NOT NULL,
                    manufacturer VARCHAR(191) NOT NULL,
                    model VARCHAR(191) NOT NULL,
                    description TEXT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_hardware_profile UNIQUE(type, manufacturer, model)
                )
            SQL);

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS hardware_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(64) NOT NULL,
                manufacturer VARCHAR(191) NOT NULL,
                model VARCHAR(191) NOT NULL,
                description TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_hardware_profile (type, manufacturer, model)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureWarehouseItemProfilesTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS warehouse_item_profiles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    item_id INTEGER NOT NULL,
                    profile_id INTEGER NOT NULL,
                    relation_type TEXT NOT NULL DEFAULT 'compatible',
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(item_id, profile_id, relation_type),
                    FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (profile_id) REFERENCES hardware_profiles(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_profiles_profile ON warehouse_item_profiles(profile_id)');

            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS warehouse_item_profiles (
                    id SERIAL PRIMARY KEY,
                    item_id INT NOT NULL,
                    profile_id INT NOT NULL,
                    relation_type VARCHAR(32) NOT NULL DEFAULT 'compatible',
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_item_profile UNIQUE(item_id, profile_id, relation_type),
                    CONSTRAINT fk_item_profile_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                    CONSTRAINT fk_item_profile_profile FOREIGN KEY (profile_id) REFERENCES hardware_profiles(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_profiles_profile ON warehouse_item_profiles(profile_id)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS warehouse_item_profiles (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                item_id INT UNSIGNED NOT NULL,
                profile_id INT UNSIGNED NOT NULL,
                relation_type VARCHAR(32) NOT NULL DEFAULT 'compatible',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_item_profile (item_id, profile_id, relation_type),
                INDEX idx_item_profiles_profile (profile_id),
                CONSTRAINT fk_item_profile_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_item_profile_profile FOREIGN KEY (profile_id) REFERENCES hardware_profiles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensurePcBuildTables(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_builds (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    reference_code TEXT NOT NULL,
                    case_id INTEGER NOT NULL,
                    case_profile_id INTEGER NULL,
                    customer_id INTEGER NULL,
                    assigned_employee TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'draft',
                    current_step TEXT NOT NULL DEFAULT 'information',
                    summary TEXT NULL,
                    planning_payload TEXT NULL,
                    planning_total_cents INTEGER NOT NULL DEFAULT 0,
                    planning_currency TEXT NOT NULL DEFAULT 'PLN',
                    assembly_payload TEXT NULL,
                    release_payload TEXT NULL,
                    approved_at TEXT NULL,
                    approved_by TEXT NULL,
                    created_by TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(reference_code),
                    FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                    FOREIGN KEY (case_profile_id) REFERENCES hardware_profiles(id) ON DELETE SET NULL,
                    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_case ON pc_builds(case_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_status ON pc_builds(status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_customer ON pc_builds(customer_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_step ON pc_builds(current_step)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_components (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    build_id INTEGER NOT NULL,
                    item_id INTEGER NOT NULL,
                    quantity INTEGER NOT NULL DEFAULT 1,
                    notes TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE,
                    FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_components_build ON pc_build_components(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_components_item ON pc_build_components(item_id)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_leftovers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    build_id INTEGER NOT NULL,
                    item_id INTEGER NOT NULL,
                    profile_id INTEGER NULL,
                    quantity INTEGER NOT NULL DEFAULT 0,
                    notes TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE,
                    FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                    FOREIGN KEY (profile_id) REFERENCES hardware_profiles(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_leftovers_build ON pc_build_leftovers(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_leftovers_profile ON pc_build_leftovers(profile_id)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_workflow (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    build_id INTEGER NOT NULL,
                    step TEXT NOT NULL,
                    payload TEXT NULL,
                    completed_at TEXT NULL,
                    completed_by TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(build_id, step),
                    FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_workflow_step ON pc_build_workflow(step)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_journal (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    build_id INTEGER NOT NULL,
                    step TEXT NOT NULL,
                    entry_type TEXT NOT NULL,
                    message TEXT NOT NULL,
                    data TEXT NULL,
                    created_by TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_journal_build ON pc_build_journal(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_journal_step ON pc_build_journal(step)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_documents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    build_id INTEGER NOT NULL,
                    type TEXT NOT NULL,
                    status TEXT NOT NULL,
                    file_path TEXT NOT NULL,
                    recipient TEXT NULL,
                    error_message TEXT NULL,
                    metadata TEXT NULL,
                    sent_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_documents_build ON pc_build_documents(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_documents_status ON pc_build_documents(status)');

            self::ensurePcBuildEnhancements($pdo);

            return;
        }

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_builds (
                    id SERIAL PRIMARY KEY,
                    reference_code VARCHAR(64) NOT NULL,
                    case_id INT NOT NULL,
                    case_profile_id INT NULL,
                    customer_id INT NULL,
                    assigned_employee VARCHAR(191) NULL,
                    status VARCHAR(64) NOT NULL DEFAULT 'draft',
                    current_step VARCHAR(32) NOT NULL DEFAULT 'information',
                    summary TEXT NULL,
                    planning_payload JSONB NULL,
                    planning_total_cents INT NOT NULL DEFAULT 0,
                    planning_currency VARCHAR(16) NOT NULL DEFAULT 'PLN',
                    assembly_payload JSONB NULL,
                    release_payload JSONB NULL,
                    approved_at TIMESTAMP NULL,
                    approved_by VARCHAR(191) NULL,
                    created_by VARCHAR(191) NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_pc_build_reference UNIQUE(reference_code),
                    CONSTRAINT fk_pc_build_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                    CONSTRAINT fk_pc_build_profile FOREIGN KEY (case_profile_id) REFERENCES hardware_profiles(id) ON DELETE SET NULL,
                    CONSTRAINT fk_pc_build_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_case ON pc_builds(case_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_status ON pc_builds(status)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_customer ON pc_builds(customer_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_builds_step ON pc_builds(current_step)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_components (
                    id SERIAL PRIMARY KEY,
                    build_id INT NOT NULL,
                    item_id INT NOT NULL,
                    quantity INT NOT NULL DEFAULT 1,
                    notes TEXT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_pc_build_component_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE,
                    CONSTRAINT fk_pc_build_component_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_components_build ON pc_build_components(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_components_item ON pc_build_components(item_id)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_leftovers (
                    id SERIAL PRIMARY KEY,
                    build_id INT NOT NULL,
                    item_id INT NOT NULL,
                    profile_id INT NULL,
                    quantity INT NOT NULL DEFAULT 0,
                    notes TEXT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_pc_build_leftover_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE,
                    CONSTRAINT fk_pc_build_leftover_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                    CONSTRAINT fk_pc_build_leftover_profile FOREIGN KEY (profile_id) REFERENCES hardware_profiles(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_leftovers_build ON pc_build_leftovers(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_leftovers_profile ON pc_build_leftovers(profile_id)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_workflow (
                    id SERIAL PRIMARY KEY,
                    build_id INT NOT NULL,
                    step VARCHAR(32) NOT NULL,
                    payload JSONB NULL,
                    completed_at TIMESTAMP NULL,
                    completed_by VARCHAR(191) NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT uniq_pc_build_workflow_step UNIQUE(build_id, step),
                    CONSTRAINT fk_pc_build_workflow_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_workflow_step ON pc_build_workflow(step)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_journal (
                    id SERIAL PRIMARY KEY,
                    build_id INT NOT NULL,
                    step VARCHAR(32) NOT NULL,
                    entry_type VARCHAR(64) NOT NULL,
                    message TEXT NOT NULL,
                    data JSONB NULL,
                    created_by VARCHAR(191) NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_pc_build_journal_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_journal_build ON pc_build_journal(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_journal_step ON pc_build_journal(step)');

            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS pc_build_documents (
                    id SERIAL PRIMARY KEY,
                    build_id INT NOT NULL,
                    type VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL,
                    file_path TEXT NOT NULL,
                    recipient VARCHAR(191) NULL,
                    error_message TEXT NULL,
                    metadata TEXT NULL,
                    sent_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_pc_build_documents_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_documents_build ON pc_build_documents(build_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pc_build_documents_status ON pc_build_documents(status)');

            self::ensurePcBuildEnhancements($pdo);

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pc_builds (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                reference_code VARCHAR(64) NOT NULL,
                case_id INT UNSIGNED NOT NULL,
                case_profile_id INT UNSIGNED NULL,
                customer_id INT UNSIGNED NULL,
                assigned_employee VARCHAR(191) NULL,
                status VARCHAR(64) NOT NULL DEFAULT 'draft',
                current_step VARCHAR(32) NOT NULL DEFAULT 'information',
                summary TEXT NULL,
                planning_payload JSON NULL,
                planning_total_cents INT NOT NULL DEFAULT 0,
                planning_currency VARCHAR(16) NOT NULL DEFAULT 'PLN',
                assembly_payload JSON NULL,
                release_payload JSON NULL,
                approved_at TIMESTAMP NULL DEFAULT NULL,
                approved_by VARCHAR(191) NULL,
                created_by VARCHAR(191) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_pc_build_reference (reference_code),
                INDEX idx_pc_builds_case (case_id),
                INDEX idx_pc_builds_status (status),
                INDEX idx_pc_builds_customer (customer_id),
                INDEX idx_pc_builds_step (current_step),
                CONSTRAINT fk_pc_build_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_pc_build_profile FOREIGN KEY (case_profile_id) REFERENCES hardware_profiles(id) ON DELETE SET NULL,
                CONSTRAINT fk_pc_build_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pc_build_components (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                build_id INT UNSIGNED NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pc_build_components_build (build_id),
                INDEX idx_pc_build_components_item (item_id),
                CONSTRAINT fk_pc_build_component_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE,
                CONSTRAINT fk_pc_build_component_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pc_build_leftovers (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                build_id INT UNSIGNED NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                profile_id INT UNSIGNED NULL,
                quantity INT NOT NULL DEFAULT 0,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pc_build_leftovers_build (build_id),
                INDEX idx_pc_build_leftovers_profile (profile_id),
                CONSTRAINT fk_pc_build_leftover_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE,
                CONSTRAINT fk_pc_build_leftover_item FOREIGN KEY (item_id) REFERENCES warehouse_items(id) ON DELETE CASCADE,
                CONSTRAINT fk_pc_build_leftover_profile FOREIGN KEY (profile_id) REFERENCES hardware_profiles(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pc_build_workflow (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                build_id INT UNSIGNED NOT NULL,
                step VARCHAR(32) NOT NULL,
                payload JSON NULL,
                completed_at TIMESTAMP NULL DEFAULT NULL,
                completed_by VARCHAR(191) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_pc_build_workflow_step (build_id, step),
                INDEX idx_pc_build_workflow_step (step),
                CONSTRAINT fk_pc_build_workflow_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pc_build_journal (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                build_id INT UNSIGNED NOT NULL,
                step VARCHAR(32) NOT NULL,
                entry_type VARCHAR(64) NOT NULL,
                message TEXT NOT NULL,
                data JSON NULL,
                created_by VARCHAR(191) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pc_build_journal_build (build_id),
                INDEX idx_pc_build_journal_step (step),
                CONSTRAINT fk_pc_build_journal_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pc_build_documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                build_id INT UNSIGNED NOT NULL,
                type VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                recipient VARCHAR(191) NULL,
                error_message TEXT NULL,
                metadata TEXT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pc_build_documents_build (build_id),
                INDEX idx_pc_build_documents_status (status),
                CONSTRAINT fk_pc_build_documents_build FOREIGN KEY (build_id) REFERENCES pc_builds(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        self::ensurePcBuildEnhancements($pdo);
    }

    private static function ensureEmployeesTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS employees (
                    id SERIAL PRIMARY KEY,
                    full_name VARCHAR(191) NOT NULL,
                    email VARCHAR(191) NULL,
                    phone VARCHAR(64) NULL,
                    role VARCHAR(64) NOT NULL DEFAULT 'staff',
                    department VARCHAR(120) NULL,
                    position VARCHAR(120) NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'active',
                    color VARCHAR(16) NULL,
                    timezone VARCHAR(64) NULL,
                    permissions JSONB NULL,
                    notes TEXT NULL,
                    hired_at DATE NULL,
                    terminated_at DATE NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uniq_employees_email ON employees(email)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employees_status ON employees(status)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS employees (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    full_name TEXT NOT NULL,
                    email TEXT NULL,
                    phone TEXT NULL,
                    role TEXT NOT NULL DEFAULT 'staff',
                    department TEXT NULL,
                    position TEXT NULL,
                    status TEXT NOT NULL DEFAULT 'active',
                    color TEXT NULL,
                    timezone TEXT NULL,
                    permissions TEXT NULL,
                    notes TEXT NULL,
                    hired_at TEXT NULL,
                    terminated_at TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_employees_email ON employees(email)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employees_status ON employees(status)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS employees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(191) NOT NULL,
                email VARCHAR(191) NULL,
                phone VARCHAR(64) NULL,
                role VARCHAR(64) NOT NULL DEFAULT 'staff',
                department VARCHAR(120) NULL,
                position VARCHAR(120) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active',
                color VARCHAR(16) NULL,
                timezone VARCHAR(64) NULL,
                permissions TEXT NULL,
                notes TEXT NULL,
                hired_at DATE NULL,
                terminated_at DATE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_employees_email (email),
                INDEX idx_employees_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureEmployeeAvailabilityTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS employee_availability (
                    id SERIAL PRIMARY KEY,
                    employee_id INT NOT NULL,
                    availability_type VARCHAR(32) NOT NULL,
                    reason VARCHAR(191) NULL,
                    start_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    end_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_employee_availability_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_availability_employee ON employee_availability(employee_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_availability_window ON employee_availability(start_at, end_at)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS employee_availability (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    employee_id INTEGER NOT NULL,
                    availability_type TEXT NOT NULL,
                    reason TEXT NULL,
                    start_at TEXT NOT NULL,
                    end_at TEXT NOT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_employee_availability_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_availability_employee ON employee_availability(employee_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_availability_window ON employee_availability(start_at, end_at)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS employee_availability (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NOT NULL,
                availability_type VARCHAR(32) NOT NULL,
                reason VARCHAR(191) NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_employee_availability_employee (employee_id),
                INDEX idx_employee_availability_window (start_at, end_at),
                CONSTRAINT fk_employee_availability_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureEmployeeAuditTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS employee_audit_log (
                    id SERIAL PRIMARY KEY,
                    employee_id INT NULL,
                    action VARCHAR(120) NOT NULL,
                    context JSONB NULL,
                    performed_by VARCHAR(120) NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_employee_audit_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_audit_employee ON employee_audit_log(employee_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_audit_action ON employee_audit_log(action)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS employee_audit_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    employee_id INTEGER NULL,
                    action TEXT NOT NULL,
                    context TEXT NULL,
                    performed_by TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_employee_audit_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_audit_employee ON employee_audit_log(employee_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_employee_audit_action ON employee_audit_log(action)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS employee_audit_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                employee_id INT UNSIGNED NULL,
                action VARCHAR(120) NOT NULL,
                context TEXT NULL,
                performed_by VARCHAR(120) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_employee_audit_employee (employee_id),
                INDEX idx_employee_audit_action (action),
                CONSTRAINT fk_employee_audit_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureCaseAssignmentsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS case_assignments (
                    id SERIAL PRIMARY KEY,
                    case_id INT NOT NULL,
                    employee_id INT NOT NULL,
                    assignment_type VARCHAR(32) NOT NULL DEFAULT 'primary',
                    assigned_by VARCHAR(120) NULL,
                    assigned_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    unassigned_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    notes TEXT NULL,
                    CONSTRAINT fk_case_assignments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                    CONSTRAINT fk_case_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_assignments_case ON case_assignments(case_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_assignments_employee ON case_assignments(employee_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_assignments_type ON case_assignments(assignment_type)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS case_assignments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NOT NULL,
                    employee_id INTEGER NOT NULL,
                    assignment_type TEXT NOT NULL DEFAULT 'primary',
                    assigned_by TEXT NULL,
                    assigned_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    unassigned_at TEXT NULL,
                    notes TEXT NULL,
                    CONSTRAINT fk_case_assignments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                    CONSTRAINT fk_case_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_assignments_case ON case_assignments(case_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_assignments_employee ON case_assignments(employee_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_case_assignments_type ON case_assignments(assignment_type)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS case_assignments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NOT NULL,
                employee_id INT UNSIGNED NOT NULL,
                assignment_type VARCHAR(32) NOT NULL DEFAULT 'primary',
                assigned_by VARCHAR(120) NULL,
                assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                unassigned_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT NULL,
                INDEX idx_case_assignments_case (case_id),
                INDEX idx_case_assignments_employee (employee_id),
                INDEX idx_case_assignments_type (assignment_type),
                CONSTRAINT fk_case_assignments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
                CONSTRAINT fk_case_assignments_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureCasePriorityColumn(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE cases ADD COLUMN IF NOT EXISTS priority VARCHAR(32)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cases_priority ON cases(priority)');

            return;
        }

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'cases', 'priority', 'TEXT');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cases_priority ON cases(priority)');

            return;
        }

        self::executeIgnoringDuplicates(
            $pdo,
            "ALTER TABLE cases ADD COLUMN priority VARCHAR(32) NULL AFTER status",
            ['duplicate column']
        );
        self::executeIgnoringDuplicates(
            $pdo,
            'CREATE INDEX idx_cases_priority ON cases(priority)',
            ['duplicate', 'exists']
        );
    }

    private static function ensureCaseSlaColumn(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE cases ADD COLUMN IF NOT EXISTS sla_due_at TIMESTAMP WITHOUT TIME ZONE NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cases_sla_due ON cases(sla_due_at)');

            return;
        }

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'cases', 'sla_due_at', 'TEXT');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cases_sla_due ON cases(sla_due_at)');

            return;
        }

        self::executeIgnoringDuplicates(
            $pdo,
            "ALTER TABLE cases ADD COLUMN sla_due_at DATETIME NULL AFTER priority",
            ['duplicate column']
        );
        self::executeIgnoringDuplicates(
            $pdo,
            'CREATE INDEX idx_cases_sla_due ON cases(sla_due_at)',
            ['duplicate', 'exists']
        );
    }

    private static function ensureCasePrimaryEmployeeColumn(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE cases ADD COLUMN IF NOT EXISTS primary_employee_id INT NULL');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cases_primary_employee ON cases(primary_employee_id)');

            try {
                $pdo->exec('ALTER TABLE cases ADD CONSTRAINT fk_cases_primary_employee FOREIGN KEY (primary_employee_id) REFERENCES employees(id) ON DELETE SET NULL');
            } catch (PDOException $exception) {
                if (!self::containsKeyword($exception, ['already exists', 'duplicate'])) {
                    throw $exception;
                }
            }

            return;
        }

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'cases', 'primary_employee_id', 'INTEGER');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_cases_primary_employee ON cases(primary_employee_id)');

            return;
        }

        self::executeIgnoringDuplicates(
            $pdo,
            "ALTER TABLE cases ADD COLUMN primary_employee_id INT UNSIGNED NULL AFTER sla_due_at",
            ['duplicate column']
        );
        self::executeIgnoringDuplicates(
            $pdo,
            'CREATE INDEX idx_cases_primary_employee ON cases(primary_employee_id)',
            ['duplicate', 'exists']
        );
        self::executeIgnoringDuplicates(
            $pdo,
            'ALTER TABLE cases ADD CONSTRAINT fk_cases_primary_employee FOREIGN KEY (primary_employee_id) REFERENCES employees(id) ON DELETE SET NULL',
            ['duplicate', 'already exists']
        );
    }

    private static function ensureAppointmentsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointments (
                    id SERIAL PRIMARY KEY,
                    case_id INT NULL,
                    customer_id INT NULL,
                    title VARCHAR(191) NOT NULL,
                    appointment_type VARCHAR(64) NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'tentative',
                    start_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    end_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    location VARCHAR(191) NULL,
                    notes TEXT NULL,
                    color VARCHAR(16) NULL,
                    confirmation_method VARCHAR(64) NULL,
                    customer_confirmation_status VARCHAR(32) NULL,
                    customer_confirmed_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    created_by VARCHAR(120) NULL,
                    updated_by VARCHAR(120) NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    resources JSONB NULL,
                    CONSTRAINT fk_appointments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    CONSTRAINT fk_appointments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_period ON appointments(start_at, end_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_status ON appointments(status)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    case_id INTEGER NULL,
                    customer_id INTEGER NULL,
                    title TEXT NOT NULL,
                    appointment_type TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'tentative',
                    start_at TEXT NOT NULL,
                    end_at TEXT NOT NULL,
                    location TEXT NULL,
                    notes TEXT NULL,
                    color TEXT NULL,
                    confirmation_method TEXT NULL,
                    customer_confirmation_status TEXT NULL,
                    customer_confirmed_at TEXT NULL,
                    created_by TEXT NULL,
                    updated_by TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    resources TEXT NULL,
                    CONSTRAINT fk_appointments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                    CONSTRAINT fk_appointments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_period ON appointments(start_at, end_at)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointments_status ON appointments(status)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS appointments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                case_id INT UNSIGNED NULL,
                customer_id INT UNSIGNED NULL,
                title VARCHAR(191) NOT NULL,
                appointment_type VARCHAR(64) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'tentative',
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                location VARCHAR(191) NULL,
                notes TEXT NULL,
                color VARCHAR(16) NULL,
                confirmation_method VARCHAR(64) NULL,
                customer_confirmation_status VARCHAR(32) NULL,
                customer_confirmed_at DATETIME NULL,
                created_by VARCHAR(120) NULL,
                updated_by VARCHAR(120) NULL,
                resources TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_appointments_period (start_at, end_at),
                INDEX idx_appointments_status (status),
                CONSTRAINT fk_appointments_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE SET NULL,
                CONSTRAINT fk_appointments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureAppointmentAttendeesTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointment_attendees (
                    id SERIAL PRIMARY KEY,
                    appointment_id INT NOT NULL,
                    employee_id INT NOT NULL,
                    attendee_role VARCHAR(64) NULL,
                    is_required BOOLEAN NOT NULL DEFAULT TRUE,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_attendees_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
                    CONSTRAINT fk_attendees_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendees_appointment ON appointment_attendees(appointment_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendees_employee ON appointment_attendees(employee_id)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointment_attendees (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    appointment_id INTEGER NOT NULL,
                    employee_id INTEGER NOT NULL,
                    attendee_role TEXT NULL,
                    is_required INTEGER NOT NULL DEFAULT 1,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_attendees_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
                    CONSTRAINT fk_attendees_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendees_appointment ON appointment_attendees(appointment_id)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendees_employee ON appointment_attendees(employee_id)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS appointment_attendees (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                appointment_id INT UNSIGNED NOT NULL,
                employee_id INT UNSIGNED NOT NULL,
                attendee_role VARCHAR(64) NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_attendees_appointment (appointment_id),
                INDEX idx_attendees_employee (employee_id),
                CONSTRAINT fk_attendees_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
                CONSTRAINT fk_attendees_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureAppointmentResourcesTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointment_resources (
                    id SERIAL PRIMARY KEY,
                    appointment_id INT NOT NULL,
                    resource_type VARCHAR(64) NOT NULL,
                    resource_label VARCHAR(191) NOT NULL,
                    details JSONB NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_resources_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_appointment ON appointment_resources(appointment_id)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointment_resources (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    appointment_id INTEGER NOT NULL,
                    resource_type TEXT NOT NULL,
                    resource_label TEXT NOT NULL,
                    details TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_resources_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_resources_appointment ON appointment_resources(appointment_id)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS appointment_resources (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                appointment_id INT UNSIGNED NOT NULL,
                resource_type VARCHAR(64) NOT NULL,
                resource_label VARCHAR(191) NOT NULL,
                details TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_resources_appointment (appointment_id),
                CONSTRAINT fk_resources_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureAppointmentNotificationsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointment_notifications (
                    id SERIAL PRIMARY KEY,
                    appointment_id INT NOT NULL,
                    channel VARCHAR(32) NOT NULL,
                    status VARCHAR(32) NOT NULL DEFAULT 'pending',
                    scheduled_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    sent_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    error TEXT NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_notification_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointment_notifications_status ON appointment_notifications(status)');

            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS appointment_notifications (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    appointment_id INTEGER NOT NULL,
                    channel TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    scheduled_at TEXT NULL,
                    sent_at TEXT NULL,
                    error TEXT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_notification_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
                )
            SQL);
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_appointment_notifications_status ON appointment_notifications(status)');

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS appointment_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                appointment_id INT UNSIGNED NOT NULL,
                channel VARCHAR(32) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                scheduled_at DATETIME NULL,
                sent_at DATETIME NULL,
                error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_appointment_notifications_status (status),
                CONSTRAINT fk_notification_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureEmployeeTables(PDO $pdo): void
    {
        self::ensureEmployeesTable($pdo);
        self::ensureEmployeeAvailabilityTable($pdo);
        self::ensureEmployeeAuditTable($pdo);
        self::ensureCaseAssignmentsTable($pdo);
    }

    private static function ensureCaseEnhancements(PDO $pdo): void
    {
        self::ensureCasePriorityColumn($pdo);
        self::ensureCaseSlaColumn($pdo);
        self::ensureCasePrimaryEmployeeColumn($pdo);
    }

    private static function ensureCalendarTables(PDO $pdo): void
    {
        self::ensureAppointmentsTable($pdo);
        self::ensureAppointmentAttendeesTable($pdo);
        self::ensureAppointmentResourcesTable($pdo);
        self::ensureAppointmentNotificationsTable($pdo);
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

    private static function ensureSqliteIndexes(PDO $pdo): void
    {
        self::createSqliteIndexIfColumnsExist(
            $pdo,
            'customers',
            ['email'],
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_email ON customers(email)'
        );
        self::createSqliteIndexIfColumnsExist(
            $pdo,
            'customers',
            ['phone'],
            'CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone)'
        );
        self::createSqliteIndexIfColumnsExist(
            $pdo,
            'devices',
            ['customer_id'],
            'CREATE INDEX IF NOT EXISTS idx_devices_customer ON devices(customer_id)'
        );
        self::createSqliteIndexIfColumnsExist(
            $pdo,
            'cases',
            ['customer_id'],
            'CREATE INDEX IF NOT EXISTS idx_cases_customer ON cases(customer_id)'
        );
        self::createSqliteIndexIfColumnsExist(
            $pdo,
            'cases',
            ['reference_code'],
            'CREATE INDEX IF NOT EXISTS idx_cases_reference ON cases(reference_code)'
        );
        self::createSqliteIndexIfColumnsExist(
            $pdo,
            'cases',
            ['status'],
            'CREATE INDEX IF NOT EXISTS idx_cases_status ON cases(status)'
        );
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
            self::addSqliteColumnIfMissing($pdo, 'notifications', 'case_id', 'INTEGER NULL');
            self::addSqliteColumnIfMissing($pdo, 'notifications', 'customer_id', 'INTEGER NULL');
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

    private static function ensureDeviceTypeColumn(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec('ALTER TABLE devices ADD COLUMN IF NOT EXISTS device_type VARCHAR(120) NULL');
            return;
        }

        if ($driver === 'sqlite') {
            self::addSqliteColumnIfMissing($pdo, 'devices', 'device_type', 'TEXT');
            return;
        }

        self::executeIgnoringDuplicates(
            $pdo,
            "ALTER TABLE devices ADD COLUMN device_type VARCHAR(120) NULL AFTER model",
            ['duplicate column', 'already exists']
        );
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

    private static function ensureDeviceComponentsTable(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS device_components (
                    id SERIAL PRIMARY KEY,
                    device_id INTEGER NOT NULL,
                    category VARCHAR(120) NOT NULL,
                    component_name VARCHAR(191) NOT NULL,
                    manufacturer VARCHAR(120) NULL,
                    model VARCHAR(191) NULL,
                    serial_number VARCHAR(120) NULL,
                    specifications TEXT NULL,
                    notes TEXT NULL,
                    asset_tag VARCHAR(120) NULL,
                    supplier VARCHAR(191) NULL,
                    purchase_reference VARCHAR(191) NULL,
                    purchase_cost VARCHAR(64) NULL,
                    inventory_location VARCHAR(191) NULL,
                    condition_status VARCHAR(64) NULL,
                    warranty_expires_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    maintenance_interval_days INTEGER NULL,
                    last_audited_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    installed_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    removed_at TIMESTAMP WITHOUT TIME ZONE NULL,
                    removal_reason TEXT NULL,
                    replaced_by_component_id INTEGER NULL,
                    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_device_components_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
                    CONSTRAINT fk_device_components_replacement FOREIGN KEY (replaced_by_component_id) REFERENCES device_components(id) ON DELETE SET NULL
                )
            SQL);
            $indexes = [
                'CREATE INDEX IF NOT EXISTS idx_device_components_device ON device_components(device_id)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_status ON device_components(device_id, removed_at)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_warranty ON device_components(device_id, warranty_expires_at)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_maintenance ON device_components(device_id, maintenance_interval_days)',
            ];

            foreach ($indexes as $sql) {
                self::executeIgnoringMissingColumns($pdo, $sql);
            }
            return;
        }

        if ($driver === 'sqlite') {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS device_components (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    device_id INTEGER NOT NULL,
                    category TEXT NOT NULL,
                    component_name TEXT NOT NULL,
                    manufacturer TEXT NULL,
                    model TEXT NULL,
                    serial_number TEXT NULL,
                    specifications TEXT NULL,
                    notes TEXT NULL,
                    asset_tag TEXT NULL,
                    supplier TEXT NULL,
                    purchase_reference TEXT NULL,
                    purchase_cost TEXT NULL,
                    inventory_location TEXT NULL,
                    condition_status TEXT NULL,
                    warranty_expires_at TEXT NULL,
                    maintenance_interval_days INTEGER NULL,
                    last_audited_at TEXT NULL,
                    installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    removed_at TEXT NULL,
                    removal_reason TEXT NULL,
                    replaced_by_component_id INTEGER NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_device_components_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
                    CONSTRAINT fk_device_components_replacement FOREIGN KEY (replaced_by_component_id) REFERENCES device_components(id) ON DELETE SET NULL
                )
            SQL);
            $indexes = [
                'CREATE INDEX IF NOT EXISTS idx_device_components_device ON device_components(device_id)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_status ON device_components(device_id, removed_at)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_warranty ON device_components(device_id, warranty_expires_at)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_maintenance ON device_components(device_id, maintenance_interval_days)',
            ];

            foreach ($indexes as $sql) {
                self::executeIgnoringMissingColumns($pdo, $sql);
            }
            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS device_components (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                device_id INT UNSIGNED NOT NULL,
                category VARCHAR(120) NOT NULL,
                component_name VARCHAR(191) NOT NULL,
                manufacturer VARCHAR(120) NULL,
                model VARCHAR(191) NULL,
                serial_number VARCHAR(120) NULL,
                specifications TEXT NULL,
                notes TEXT NULL,
                asset_tag VARCHAR(120) NULL,
                supplier VARCHAR(191) NULL,
                purchase_reference VARCHAR(191) NULL,
                purchase_cost VARCHAR(64) NULL,
                inventory_location VARCHAR(191) NULL,
                condition_status VARCHAR(64) NULL,
                warranty_expires_at DATETIME NULL,
                maintenance_interval_days INT NULL,
                last_audited_at DATETIME NULL,
                installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                removed_at DATETIME NULL,
                removal_reason TEXT NULL,
                replaced_by_component_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_device_components_device (device_id),
                INDEX idx_device_components_status (device_id, removed_at),
                INDEX idx_device_components_warranty (device_id, warranty_expires_at),
                INDEX idx_device_components_maintenance (device_id, maintenance_interval_days),
                CONSTRAINT fk_device_components_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
                CONSTRAINT fk_device_components_replacement FOREIGN KEY (replaced_by_component_id) REFERENCES device_components(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    private static function ensureDeviceComponentEnhancements(PDO $pdo): void
    {
        $driver = self::databaseDriver($pdo);

        if ($driver === 'pgsql') {
            $columns = [
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS asset_tag VARCHAR(120)",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS supplier VARCHAR(191)",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS purchase_reference VARCHAR(191)",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS purchase_cost VARCHAR(64)",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS inventory_location VARCHAR(191)",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS condition_status VARCHAR(64)",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS warranty_expires_at TIMESTAMP WITHOUT TIME ZONE",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS maintenance_interval_days INTEGER",
                "ALTER TABLE device_components ADD COLUMN IF NOT EXISTS last_audited_at TIMESTAMP WITHOUT TIME ZONE"
            ];

            foreach ($columns as $sql) {
                $pdo->exec($sql);
            }

            $indexes = [
                'CREATE INDEX IF NOT EXISTS idx_device_components_warranty ON device_components(device_id, warranty_expires_at)',
                'CREATE INDEX IF NOT EXISTS idx_device_components_maintenance ON device_components(device_id, maintenance_interval_days)'
            ];

            foreach ($indexes as $sql) {
                $pdo->exec($sql);
            }

            return;
        }

        if ($driver === 'sqlite') {
            $columns = [
                ['asset_tag', 'TEXT'],
                ['supplier', 'TEXT'],
                ['purchase_reference', 'TEXT'],
                ['purchase_cost', 'TEXT'],
                ['inventory_location', 'TEXT'],
                ['condition_status', 'TEXT'],
                ['warranty_expires_at', 'TEXT'],
                ['maintenance_interval_days', 'INTEGER'],
                ['last_audited_at', 'TEXT'],
            ];

            foreach ($columns as [$name, $definition]) {
                self::addSqliteColumnIfMissing($pdo, 'device_components', $name, $definition);
            }

            if (self::sqliteColumnExists($pdo, 'device_components', 'warranty_expires_at')) {
                self::executeIgnoringMissingColumns(
                    $pdo,
                    'CREATE INDEX IF NOT EXISTS idx_device_components_warranty ON device_components(device_id, warranty_expires_at)'
                );
            }

            if (self::sqliteColumnExists($pdo, 'device_components', 'maintenance_interval_days')) {
                self::executeIgnoringMissingColumns(
                    $pdo,
                    'CREATE INDEX IF NOT EXISTS idx_device_components_maintenance ON device_components(device_id, maintenance_interval_days)'
                );
            }

            return;
        }

        $columns = [
            "ALTER TABLE device_components ADD COLUMN asset_tag VARCHAR(120) NULL AFTER notes",
            "ALTER TABLE device_components ADD COLUMN supplier VARCHAR(191) NULL AFTER asset_tag",
            "ALTER TABLE device_components ADD COLUMN purchase_reference VARCHAR(191) NULL AFTER supplier",
            "ALTER TABLE device_components ADD COLUMN purchase_cost VARCHAR(64) NULL AFTER purchase_reference",
            "ALTER TABLE device_components ADD COLUMN inventory_location VARCHAR(191) NULL AFTER purchase_cost",
            "ALTER TABLE device_components ADD COLUMN condition_status VARCHAR(64) NULL AFTER inventory_location",
            "ALTER TABLE device_components ADD COLUMN warranty_expires_at DATETIME NULL AFTER condition_status",
            "ALTER TABLE device_components ADD COLUMN maintenance_interval_days INT NULL AFTER warranty_expires_at",
            "ALTER TABLE device_components ADD COLUMN last_audited_at DATETIME NULL AFTER maintenance_interval_days"
        ];

        foreach ($columns as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate column']);
        }

        $indexes = [
            'ALTER TABLE device_components ADD INDEX idx_device_components_warranty (device_id, warranty_expires_at)',
            'ALTER TABLE device_components ADD INDEX idx_device_components_maintenance (device_id, maintenance_interval_days)'
        ];

        foreach ($indexes as $sql) {
            self::executeIgnoringDuplicates($pdo, $sql, ['duplicate', 'already exists']);
        }
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

    private static function createSqliteIndexIfColumnsExist(PDO $pdo, string $table, array $columns, string $sql): void
    {
        foreach ($columns as $column) {
            if (!self::sqliteColumnExists($pdo, $table, $column)) {
                return;
            }
        }

        $pdo->exec($sql);
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

    private static function executeIgnoringMissingColumns(PDO $pdo, string $sql): void
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException $exception) {
            $keywords = [
                'no such column',
                'does not exist',
                'undefined column',
                'unknown column',
            ];

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