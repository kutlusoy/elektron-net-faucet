<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class RateLimiter
{
    public static function check(string $address, string $ip): ?string
    {
        $s = Db::getAllSettings();
        $perAddrHours = (int)($s['per_addr_cooldown_h'] ?? 24);
        $perIpHours   = (int)($s['per_ip_cooldown_h'] ?? 1);

        $ipBin = self::ipToBin($ip);

        if ($perAddrHours > 0) {
            $last = Db::fetchValue(
                "SELECT created_at FROM claims
                 WHERE address = ? AND status <> 'failed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 ORDER BY id DESC LIMIT 1",
                [$address, $perAddrHours]
            );
            if ($last !== null) {
                return I18n::t('err.cooldown_address', ['hours' => $perAddrHours]);
            }
        }

        if ($perIpHours > 0) {
            $last = Db::fetchValue(
                "SELECT created_at FROM claims
                 WHERE ip = ? AND status <> 'failed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 ORDER BY id DESC LIMIT 1",
                [$ipBin, $perIpHours]
            );
            if ($last !== null) {
                return I18n::t('err.cooldown_ip', ['hours' => $perIpHours]);
            }
        }

        $amountSat = self::elekToSat((string)($s['amount_elek'] ?? '0'));

        $hourBudgetSat = self::elekToSat((string)($s['hourly_budget'] ?? '0'));
        if ($hourBudgetSat > 0) {
            $spent = (int)Db::fetchValue(
                "SELECT COALESCE(SUM(amount_satoshi),0) FROM claims
                 WHERE status <> 'failed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            if ($spent + $amountSat > $hourBudgetSat) {
                return I18n::t('err.hourly_exhausted');
            }
        }

        $dayBudgetSat = self::elekToSat((string)($s['daily_budget'] ?? '0'));
        if ($dayBudgetSat > 0) {
            $spent = (int)Db::fetchValue(
                "SELECT COALESCE(SUM(amount_satoshi),0) FROM claims
                 WHERE status <> 'failed'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
            if ($spent + $amountSat > $dayBudgetSat) {
                return I18n::t('err.daily_exhausted');
            }
        }

        return null;
    }

    public static function ipToBin(string $ip): string
    {
        $bin = @inet_pton($ip);
        return $bin === false ? str_repeat("\0", 4) : $bin;
    }

    public static function elekToSat(string $amount): int
    {
        $a = trim($amount);
        if ($a === '' || !preg_match('/^\d+(\.\d+)?$/', $a)) return 0;
        $parts = explode('.', $a);
        $whole = (int)$parts[0];
        $frac = str_pad($parts[1] ?? '', 8, '0', STR_PAD_RIGHT);
        $frac = substr($frac, 0, 8);
        return $whole * 100_000_000 + (int)$frac;
    }

    public static function satToElek(int $sat): string
    {
        $sign = $sat < 0 ? '-' : '';
        $sat = abs($sat);
        $whole = intdiv($sat, 100_000_000);
        $frac = $sat % 100_000_000;
        return $sign . $whole . '.' . str_pad((string)$frac, 8, '0', STR_PAD_LEFT);
    }
}
