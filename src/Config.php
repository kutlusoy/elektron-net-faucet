<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Config
{
    /** @var array<string,mixed> */
    private static array $data = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('config.php not found or not readable');
        }
        $cfg = require $path;
        if (!is_array($cfg)) {
            throw new \RuntimeException('config.php must return an array');
        }
        foreach (['db_host', 'db_name', 'db_user', 'db_pass', 'app_key'] as $req) {
            if (!isset($cfg[$req]) || $cfg[$req] === '') {
                throw new \RuntimeException("config.php missing required key: $req");
            }
        }
        if (strlen((string)$cfg['app_key']) < 32) {
            throw new \RuntimeException('app_key must be at least 32 chars (hex-encoded 32 bytes recommended)');
        }
        self::$data = $cfg;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::$data;
    }
}
