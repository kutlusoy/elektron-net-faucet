<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class RateLimiter
{
    // Fixed fee headroom required on top of the claim amount when checking
    // the faucet's own live balance -- large enough for a single standard
    // payout transaction's fee at any realistic fee rate, small enough to
    // never meaningfully affect payout eligibility.
    private const OWN_BALANCE_FEE_BUFFER_SAT = 10_000;

    /**
     * Decides whether $address may claim right now. Everything that can be
     * verified against the chain is -- address balance, the faucet's own
     * solvency, and spend budgets all come from live node RPC via $wallet,
     * not from this app's own bookkeeping. The database is only consulted
     * for two things no blockchain can answer: per-IP cooldown (IP isn't
     * on-chain data), and a short guard against double-claiming the same
     * address while its first payout is still unconfirmed (scantxoutset
     * only sees the confirmed UTXO set, so a just-broadcast payout is
     * briefly invisible on-chain).
     */
    public static function check(string $address, string $ip, Wallet $wallet): ?string
    {
        $s = Db::getAllSettings();
        $pendingGuardMin = (int)($s['pending_guard_min'] ?? 30);
        $perIpHours      = (int)($s['per_ip_cooldown_h'] ?? 1);

        $ipBin = self::ipToBin($ip);

        if ($pendingGuardMin > 0) {
            $last = Db::fetchValue(
                "SELECT created_at FROM claims
                 WHERE address = ? AND status IN ('pending','sent')
                   AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
                 ORDER BY id DESC LIMIT 1",
                [$address, $pendingGuardMin]
            );
            if ($last !== null) {
                return I18n::t('err.pending_guard', ['minutes' => $pendingGuardMin]);
            }
        }

        try {
            if ($wallet->addressHasOnChainBalance($address)) {
                return I18n::t('err.address_funded');
            }
        } catch (\Throwable) {
            return I18n::t('err.busy');
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

        // amountSat === 0 means the faucet is disabled -- public/api.php
        // reports that specifically right after check() returns null, so
        // skip the solvency check rather than misreport a healthy-but-small
        // balance as "insufficient funds".
        if ($amountSat > 0) {
            try {
                $ownBalanceSat = self::elekToSat($wallet->getBalance());
            } catch (\Throwable) {
                return I18n::t('err.busy');
            }
            if ($amountSat + self::OWN_BALANCE_FEE_BUFFER_SAT > $ownBalanceSat) {
                return I18n::t('err.insufficient_funds');
            }
        }

        $hourBudgetSat = self::elekToSat((string)($s['hourly_budget'] ?? '0'));
        if ($hourBudgetSat > 0) {
            try {
                $spent = $wallet->getSentSatSince(time() - 3600);
            } catch (\Throwable) {
                return I18n::t('err.busy');
            }
            if ($spent + $amountSat > $hourBudgetSat) {
                return I18n::t('err.hourly_exhausted');
            }
        }

        $dayBudgetSat = self::elekToSat((string)($s['daily_budget'] ?? '0'));
        if ($dayBudgetSat > 0) {
            try {
                $spent = $wallet->getSentSatSince(time() - 86400);
            } catch (\Throwable) {
                return I18n::t('err.busy');
            }
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
