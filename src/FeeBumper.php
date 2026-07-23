<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * Finds faucet payouts that were broadcast but are still unconfirmed after
 * a while and RBF fee-bumps them to the node's current fee floor
 * (Wallet::currentFallbackFeeRateSatsPerVb). Hooked up to both the
 * self-throttled cron tick (bin/fee-bump-cron.php) and the "check stuck
 * transactions now" button in the Admin Panel.
 */
final class FeeBumper
{
    /**
     * @return array{checked:int,bumped:int,confirmed:int,skipped:int,errors:array<int,string>}
     */
    public static function sweepStuckClaims(Wallet $wallet, int $minAgeMinutes = 0): array
    {
        $summary = ['checked' => 0, 'bumped' => 0, 'confirmed' => 0, 'skipped' => 0, 'errors' => []];

        $claims = Db::fetchAll(
            "SELECT id, txid FROM claims
             WHERE status = 'sent' AND txid IS NOT NULL
               AND sent_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY id ASC",
            [max(0, $minAgeMinutes)]
        );

        if ($claims === []) {
            return $summary;
        }

        $desiredFeeRate = $wallet->currentFallbackFeeRateSatsPerVb();

        foreach ($claims as $claim) {
            $summary['checked']++;
            $claimId = (int)$claim['id'];
            $txid    = (string)$claim['txid'];

            $confs = $wallet->getConfirmations($txid);
            if ($confs !== null && $confs > 0) {
                $summary['confirmed']++;
                continue;
            }

            $targetRate = self::cpfpTargetFeeRate($wallet, $txid, $desiredFeeRate);

            try {
                $newTxid = $wallet->bumpFee($txid, $targetRate);
                Db::exec('UPDATE claims SET txid = ? WHERE id = ?', [$newTxid, $claimId]);
                Logger::audit(null, 'claim_fee_bumped', [
                    'claim_id' => $claimId,
                    'old_txid' => $txid,
                    'new_txid' => $newTxid,
                    'fee_rate' => $targetRate,
                ]);
                $summary['bumped']++;
            } catch (\Throwable $e) {
                // Most commonly "Transaction has descendants in the wallet"
                // -- this hot wallet chains payouts (a later claim's input
                // is an earlier claim's still-unconfirmed change output),
                // and Core flatly refuses to bump a tx that already has a
                // wallet descendant. That's expected for a non-tip link in
                // such a chain, not a real failure: once the chain's tip
                // gets bumped and confirms, every ancestor confirms with it
                // (a block can't include a child without its parents), so
                // this one resolves on its own without ever being bumped
                // directly.
                $summary['skipped']++;
                $summary['errors'][] = "#{$claimId} {$txid}: " . $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * Sizes a bump to cover the transaction's entire unconfirmed ancestor
     * package (itself + whatever unconfirmed inputs it still spends) at
     * $desiredFeeRateSatsPerVb, not just its own small vsize -- otherwise a
     * long low-fee ancestor chain stays underpaid even after its tip is
     * "bumped", since a low-fee parent's own feerate never changes just
     * because its child paid more. Falls back to the flat desired rate if
     * the node no longer has the transaction in mempool (bumpFee() then
     * surfaces a clear error of its own).
     */
    private static function cpfpTargetFeeRate(Wallet $wallet, string $txid, float $desiredFeeRateSatsPerVb): float
    {
        $entry = $wallet->getMempoolEntry($txid);
        $ancestorSizeVb = (int)($entry['ancestorsize'] ?? 0);
        $ownVsize       = (int)($entry['vsize'] ?? 0);
        if ($entry === null || $ancestorSizeVb <= 0 || $ownVsize <= 0) {
            return $desiredFeeRateSatsPerVb;
        }

        $ancestorFeeSat      = (float)($entry['fees']['ancestor'] ?? 0) * 100_000_000;
        $ownFeeSat           = (float)($entry['fees']['base'] ?? 0) * 100_000_000;
        $targetPackageFeeSat = ceil($ancestorSizeVb * $desiredFeeRateSatsPerVb);
        $additionalNeededSat = max(0.0, $targetPackageFeeSat - $ancestorFeeSat);

        return max($desiredFeeRateSatsPerVb, ($ownFeeSat + $additionalNeededSat) / $ownVsize);
    }
}
