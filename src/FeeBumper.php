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

        $feeRate = $wallet->currentFallbackFeeRateSatsPerVb();

        foreach ($claims as $claim) {
            $summary['checked']++;
            $claimId = (int)$claim['id'];
            $txid    = (string)$claim['txid'];

            $confs = $wallet->getConfirmations($txid);
            if ($confs !== null && $confs > 0) {
                $summary['confirmed']++;
                continue;
            }

            try {
                $newTxid = $wallet->bumpFee($txid, $feeRate);
                Db::exec('UPDATE claims SET txid = ? WHERE id = ?', [$newTxid, $claimId]);
                Logger::audit(null, 'claim_fee_bumped', [
                    'claim_id' => $claimId,
                    'old_txid' => $txid,
                    'new_txid' => $newTxid,
                    'fee_rate' => $feeRate,
                ]);
                $summary['bumped']++;
            } catch (\Throwable $e) {
                $summary['skipped']++;
                $summary['errors'][] = "#{$claimId} {$txid}: " . $e->getMessage();
            }
        }

        return $summary;
    }
}
