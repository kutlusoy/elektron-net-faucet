<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Stats
{
    public static function totalSentSat(): int
    {
        return (int)Db::fetchValue(
            "SELECT COALESCE(SUM(amount_satoshi),0) FROM claims WHERE status = 'sent'"
        );
    }

    public static function totalSentCount(): int
    {
        return (int)Db::fetchValue(
            "SELECT COUNT(*) FROM claims WHERE status = 'sent'"
        );
    }

    public static function spentLastHourSat(): int
    {
        return (int)Db::fetchValue(
            "SELECT COALESCE(SUM(amount_satoshi),0) FROM claims
             WHERE status <> 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }

    public static function spentLastDaySat(): int
    {
        return (int)Db::fetchValue(
            "SELECT COALESCE(SUM(amount_satoshi),0) FROM claims
             WHERE status <> 'failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
        );
    }

    /**
     * @return array<int,array{hour:string,sat:int,count:int}>
     */
    public static function last24hHistogram(): array
    {
        $rows = Db::fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') AS hour,
                    COALESCE(SUM(amount_satoshi),0) AS sat,
                    COUNT(*) AS cnt
             FROM claims
             WHERE status = 'sent' AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY hour
             ORDER BY hour"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['hour' => (string)$r['hour'], 'sat' => (int)$r['sat'], 'count' => (int)$r['cnt']];
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recentClaims(int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        return Db::fetchAll(
            "SELECT id, address, amount_satoshi, txid, status, created_at, sent_at, error
             FROM claims ORDER BY id DESC LIMIT $limit"
        );
    }
}
