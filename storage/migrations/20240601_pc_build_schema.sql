-- Migration: PC builder schema (builds, workflow, components, documents)
-- This script mirrors the runtime schema initialisation performed by SchemaManager
-- and can be applied on MySQL-compatible databases.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;