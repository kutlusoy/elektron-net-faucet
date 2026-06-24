<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Logger
{
    public static function audit(?int $adminId, string $action, array $details = []): void
    {
        try {
            Db::exec(
                'INSERT INTO audit_log (admin_id, action, details, ip) VALUES (?, ?, ?, ?)',
                [
                    $adminId,
                    $action,
                    json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    RateLimiter::ipToBin(self::clientIp()),
                ]
            );
        } catch (\Throwable) {}
    }

    public static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
