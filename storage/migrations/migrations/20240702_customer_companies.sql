CREATE TABLE IF NOT EXISTS customer_companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    kvk VARCHAR(32) NOT NULL,
    btw VARCHAR(32) NULL,
    contact_person VARCHAR(191) NOT NULL,
    email VARCHAR(191) NULL,
    phone VARCHAR(32) NULL,
    address VARCHAR(255) NULL,
    postal_code VARCHAR(16) NULL,
    city VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY customer_companies_customer_id_unique (customer_id),
    CONSTRAINT fk_customer_companies_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;