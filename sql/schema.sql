-- Elektron Net Faucet — MySQL/MariaDB Schema
-- Fresh install: mysql -u <user> -p <db> < sql/schema.sql
-- Existing install: schema is applied automatically on every bootstrap (idempotent).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS settings (
    `key`      VARCHAR(64)  NOT NULL PRIMARY KEY,
    `value`    MEDIUMTEXT   NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(64)   NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME      NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS claims (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    address        VARCHAR(90)     NOT NULL,
    ip             VARBINARY(16)   NOT NULL,
    amount_satoshi BIGINT UNSIGNED NOT NULL,
    txid           CHAR(64)        NULL,
    status         ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    error          TEXT            NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at        DATETIME        NULL,
    INDEX idx_created      (created_at),
    INDEX idx_addr_created (address, created_at),
    INDEX idx_ip_created   (ip, created_at),
    INDEX idx_status       (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id   INT UNSIGNED    NULL,
    action     VARCHAR(64)     NOT NULL,
    details    JSON            NULL,
    ip         VARBINARY(16)   NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin   (admin_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
    id         CHAR(64)     NOT NULL PRIMARY KEY,
    admin_id   INT UNSIGNED NOT NULL,
    data       TEXT         NULL,
    csrf_token CHAR(64)     NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,
    INDEX idx_expires (expires_at),
    INDEX idx_admin   (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip         VARBINARY(16)   NOT NULL,
    username   VARCHAR(64)     NULL,
    success    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_created (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
