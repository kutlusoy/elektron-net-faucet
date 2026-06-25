-- Migration for existing installations: adds donations support
-- Safe to run multiple times (IF NOT EXISTS guards every statement)
-- Run: mysql -u <user> -p <db> < sql/donations_migration.sql

CREATE TABLE IF NOT EXISTS donations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    amount_elek  DECIMAL(18,8)   NOT NULL DEFAULT 0.00000000,
    donor_name   VARCHAR(100)    NULL,
    message      VARCHAR(500)    NULL,
    ip           VARBINARY(16)   NOT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
