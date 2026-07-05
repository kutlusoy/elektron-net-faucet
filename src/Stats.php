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

    /**
     * Incoming donations to $address, newest first, straight from the node
     * via RPC. Used by syncDonations() to feed the donations ledger below —
     * prefer totalDonatedSat()/recentDonations() for anything display-facing.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function donationsTo(RpcClient $rpc, string $address, int $scanLimit = 500): array
    {
        $out = [];
        $txs = $rpc->call('listtransactions', ['*', $scanLimit, 0, true]);
        if (is_array($txs)) {
            foreach ($txs as $tx) {
                if (($tx['category'] ?? '') === 'receive' && ($tx['address'] ?? '') === $address) {
                    $out[] = $tx;
                }
            }
            usort($out, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
        }
        return $out;
    }

    /**
     * Pull recent wallet receives to $address from the node and upsert them
     * into the donations ledger (DB). Once a txid is stored it stays,
     * so totals are unaffected by later address rotation or by the donation
     * eventually aging out of the RPC scan window. Throttled via the
     * `donations_synced_at` setting so a busy public page doesn't hit the
     * node's RPC on every request.
     *
     * Caveat: this only survives pruning (`prune=` in bitcoin.conf) as long
     * as the wallet already recorded the donation while its block was still
     * on disk. If the wallet ever needs `rescanblockchain` to pick up
     * donations it doesn't have yet (fresh wallet import, restored backup,
     * a sync gap longer than the prune window), a pruned node can only
     * rescan back to the prune point — anything older is unrecoverable via
     * RPC. See the "Donations" section in README.md.
     */
    public static function syncDonations(RpcClient $rpc, string $address, int $minIntervalSeconds = 30, int $scanLimit = 500): void
    {
        $last = (int)Db::getSetting('donations_synced_at', '0');
        if (time() - $last < $minIntervalSeconds) {
            return;
        }

        foreach (self::donationsTo($rpc, $address, $scanLimit) as $tx) {
            $txid = (string)($tx['txid'] ?? '');
            if ($txid === '') continue;

            $amountSat = (int)round((float)($tx['amount'] ?? 0) * 100_000_000);
            $confs     = (int)($tx['confirmations'] ?? 0);
            $blockTime = isset($tx['blocktime']) ? date('Y-m-d H:i:s', (int)$tx['blocktime']) : null;

            Db::exec(
                'INSERT INTO donations (txid, address, amount_satoshi, confirmations, block_time)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE confirmations = VALUES(confirmations), block_time = VALUES(block_time)',
                [$txid, $address, $amountSat, $confs, $blockTime]
            );
        }

        Db::setSetting('donations_synced_at', (string)time());
    }

    public static function totalDonatedSat(): int
    {
        return (int)Db::fetchValue('SELECT COALESCE(SUM(amount_satoshi),0) FROM donations');
    }

    public static function totalDonatedCount(): int
    {
        return (int)Db::fetchValue('SELECT COUNT(*) FROM donations');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function recentDonations(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        return Db::fetchAll(
            "SELECT txid, address, amount_satoshi, confirmations, block_time
             FROM donations ORDER BY block_time DESC, txid DESC LIMIT $limit"
        );
    }
}
