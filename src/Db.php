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
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
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

    /**
     * @return array<string,string>
     */
    public static function getAllSettings(): array
    {
        $rows = self::fetchAll('SELECT `key`, `value` FROM settings');
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['key']] = (string)$r['value'];
        }
        return $out;
    }
}
