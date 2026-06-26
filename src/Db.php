<?php
declare(strict_types=1);

namespace ElektronFaucet;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = (string)Config::get('db_host', '127.0.0.1');
            $port = (int)Config::get('db_port', 3306);
            $name = (string)Config::get('db_name');
            $user = (string)Config::get('db_user');
            $pass = (string)Config::get('db_pass');
            $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** @return array<int,array<string,mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }

    public static function setSetting(string $key, ?string $value): void
    {
        self::exec(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }

    public static function getSetting(string $key, ?string $default = null): ?string
    {
        $v = self::fetchValue('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
        return $v === null ? $default : (string)$v;
    }

    /** @return array<string,string> */
    public static function getAllSettings(): array
    {
        $rows = self::fetchAll('SELECT `key`, `value` FROM settings');
        $out  = [];
        foreach ($rows as $r) {
            $out[(string)$r['key']] = (string)$r['value'];
        }
        return $out;
    }

    /**
     * Idempotent schema migration — runs on every bootstrap.
     * Uses CREATE TABLE IF NOT EXISTS so it is safe to call repeatedly.
     */
    public static function migrate(): void
    {
        $pdo = self::pdo();
        $statements = [
            "CREATE TABLE IF NOT EXISTS settings (
                `key`      VARCHAR(64)  NOT NULL PRIMARY KEY,
                `value`    MEDIUMTEXT   NULL,
                updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS admin_users (
                id            INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username      VARCHAR(64)   NOT NULL UNIQUE,
                password_hash VARCHAR(255)  NOT NULL,
                created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_login    DATETIME      NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS claims (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS audit_log (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                admin_id   INT UNSIGNED    NULL,
                action     VARCHAR(64)     NOT NULL,
                details    JSON            NULL,
                ip         VARBINARY(16)   NULL,
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_admin   (admin_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS sessions (
                id         CHAR(64)     NOT NULL PRIMARY KEY,
                admin_id   INT UNSIGNED NOT NULL,
                data       TEXT         NULL,
                csrf_token CHAR(64)     NOT NULL,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME     NOT NULL,
                INDEX idx_expires (expires_at),
                INDEX idx_admin   (admin_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS login_attempts (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ip         VARBINARY(16)   NOT NULL,
                username   VARCHAR(64)     NULL,
                success    TINYINT(1)      NOT NULL DEFAULT 0,
                created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip_created (ip, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

}
