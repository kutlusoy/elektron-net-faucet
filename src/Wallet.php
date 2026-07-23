<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * Faucet payout flow:
 *   walletpassphrase(pw, 10) -> sendtoaddress -> walletlock
 * Wallet stays unlocked for at most a few seconds, only long enough for one
 * RPC call (send or bumpFee).
 */
final class Wallet
{
    // Absolute floor used only if the node cannot report a mempoolminfee at
    // all (fresh node, RPC hiccup) -- keeps sendtoaddress/bumpfee from being
    // called with a zero fee_rate, which core rejects outright.
    private const MIN_FEE_RATE_SATS_PER_VB = 1.0;

    // Safety margin added on top of the node's live mempoolminfee so a
    // fallback/bump clears the *current* acceptance floor with room to
    // spare, instead of a fixed historical guess that can fall behind once
    // the mempool fills up and mempoolminfee rises above it.
    private const FEE_RATE_MARGIN = 1.25;

    public function __construct(private RpcClient $rpc) {}

    public static function fromSettings(): self
    {
        $s = Db::getAllSettings();
        $host = $s['rpc_host'] ?? '127.0.0.1';
        $port = (int)($s['rpc_port'] ?? 8332);
        $user = $s['rpc_user'] ?? '';
        $passEnc = $s['rpc_pass_enc'] ?? '';
        $pass = $passEnc !== '' ? Crypto::decrypt($passEnc, 'rpc_pass') : '';
        $wallet = $s['wallet_name'] ?? '';
        $verifyPeer = ($s['rpc_tls_verify'] ?? '1') !== '0';
        // Disable hostname verification to support wildcard certificates;
        // peer verification (cert chain) still applies when verifyPeer is true.
        $verifyHost = ($s['rpc_tls_verify_host'] ?? '0') !== '0';
        return new self(new RpcClient(
            $host, $port, $user, $pass,
            $wallet === '' ? null : $wallet,
            30,
            $verifyPeer,
            $verifyHost,
        ));
    }

    public function rpc(): RpcClient
    {
        return $this->rpc;
    }

    public function send(string $address, string $amountElek, string $comment = 'faucet'): string
    {
        return $this->withUnlockedWallet(function () use ($address, $amountElek, $comment) {
            $txid = $this->sendToAddressWithFeeFallback($address, $amountElek, $comment);
            if (!is_string($txid) || strlen($txid) !== 64) {
                throw new \RuntimeException('sendtoaddress returned unexpected result');
            }
            return $txid;
        });
    }

    private function sendToAddressWithFeeFallback(string $address, string $amountElek, string $comment): mixed
    {
        try {
            // replaceable=true so every faucet payout is BIP125-eligible and
            // can later be RBF fee-bumped by FeeBumper if it stalls
            // unconfirmed (see bumpFee() below).
            return $this->rpc->call('sendtoaddress', [$address, $amountElek, $comment, '', false, true]);
        } catch (RpcException $e) {
            if (stripos($e->getMessage(), 'fee estimation failed') === false) {
                throw $e;
            }
            $feeRate = $this->currentFallbackFeeRateSatsPerVb();
            // 3 nulls fill conf_target/estimate_mode/avoid_reuse between
            // replaceable and fee_rate (see sendtoaddress() in
            // src/wallet/rpc/spend.cpp) -- a positional null means "not
            // provided" for optional RPC args.
            return $this->rpc->call('sendtoaddress', [
                $address, $amountElek, $comment, '', false, true,
                null, null, null,
                $feeRate,
            ]);
        }
    }

    /**
     * Live floor for a fee_rate fallback/bump: the node's current
     * mempoolminfee (the dynamic floor that rises above minrelaytxfee once
     * the mempool fills up), converted from BTC/kvB to sat/vB and padded by
     * FEE_RATE_MARGIN. A fixed historical guess (e.g. "1 sat/vB, always")
     * can fall behind actual network conditions and produce a transaction
     * that gets accepted into the mempool but never picked up by a miner --
     * this keeps the fallback tied to what the node reports right now.
     */
    public function currentFallbackFeeRateSatsPerVb(): float
    {
        try {
            $info = $this->rpc->call('getmempoolinfo', []);
            $minFeeBtcPerKvb = (float)($info['mempoolminfee'] ?? 0);
        } catch (\Throwable) {
            $minFeeBtcPerKvb = 0.0;
        }
        // BTC/kvB -> sat/vB: * 1e8 sat/BTC / 1000 vB/kvB = * 100_000
        $satPerVb = $minFeeBtcPerKvb * 100_000 * self::FEE_RATE_MARGIN;
        // Round up to the nearest 0.1 sat/vB so we never land a hair under
        // the floor we just measured.
        $satPerVb = ceil($satPerVb * 10) / 10;
        return max(self::MIN_FEE_RATE_SATS_PER_VB, $satPerVb);
    }

    /**
     * RBF-replace an unconfirmed, wallet-owned transaction with one paying
     * at least $feeRateSatsPerVb (defaults to the live fallback floor
     * above). Requires the original tx to have been sent with
     * replaceable=true -- true for every payout since this fix; payouts
     * sent before it will fail here with "Transaction is not BIP 125
     * replaceable". Also fails outright (by design, not caught here) if the
     * transaction already has a descendant in the wallet -- Core refuses to
     * bump those; see FeeBumper for why that's fine to just skip. Returns
     * the new txid.
     */
    public function bumpFee(string $txid, ?float $feeRateSatsPerVb = null): string
    {
        $feeRate = $feeRateSatsPerVb ?? $this->currentFallbackFeeRateSatsPerVb();
        return $this->withUnlockedWallet(function () use ($txid, $feeRate) {
            return $this->callBumpFee($txid, $feeRate);
        });
    }

    private function callBumpFee(string $txid, float $feeRateSatsPerVb): string
    {
        // Round UP (never down, so we never drop back below what the caller
        // actually wanted) to 3 decimals. A raw division result (e.g. from
        // FeeBumper's CPFP package sizing) can carry a dozen+ significant
        // digits, and core's fee_rate amount parser rejects that outright
        // with "Invalid amount" -- observed live against this fork.
        $feeRateSatsPerVb = self::roundFeeRateUp($feeRateSatsPerVb);
        try {
            $result = $this->rpc->call('bumpfee', [$txid, ['fee_rate' => $feeRateSatsPerVb]]);
        } catch (RpcException $e) {
            // BIP125 rule 4: the replacement must pay the old fee plus at
            // least the incremental relay fee -- our own fee_rate can land
            // right back at (or below) that boundary on a quiet chain where
            // mempoolminfee is near zero. Core states the exact minimum
            // total fee it requires right in the error text, so reuse that
            // number directly (with a margin so the retry doesn't land on
            // the boundary again either) instead of re-deriving
            // incrementalrelayfee ourselves, and retry once.
            if (!preg_match('/must be at least ([0-9.]+)/', $e->getMessage(), $m)) {
                throw $e;
            }
            $entry = $this->getMempoolEntry($txid);
            $vsize = (int)($entry['vsize'] ?? 0);
            if ($vsize <= 0) {
                throw $e;
            }
            $requiredSat = (int)ceil(((float)$m[1]) * 100_000_000 * 1.1);
            $retryRate   = self::roundFeeRateUp(max($feeRateSatsPerVb, $requiredSat / $vsize));
            $result = $this->rpc->call('bumpfee', [$txid, ['fee_rate' => $retryRate]]);
        }
        if (!is_array($result) || empty($result['txid'])) {
            // A watch-only/PSBT-only wallet would return a 'psbt' to
            // sign manually instead of a broadcast txid -- our hot
            // wallet always holds the private key, so treat anything
            // else as an error rather than pretending it succeeded.
            throw new \RuntimeException(
                'bumpfee did not return a broadcast txid: ' . json_encode($result)
            );
        }
        return (string)$result['txid'];
    }

    private static function roundFeeRateUp(float $rate): float
    {
        return ceil($rate * 1000) / 1000;
    }

    /**
     * Confirmation count for a wallet-owned transaction, or null if the
     * node doesn't know about it (already evicted, wrong wallet, ...).
     */
    public function getConfirmations(string $txid): ?int
    {
        try {
            $tx = $this->rpc->call('gettransaction', [$txid]);
            return is_array($tx) ? (int)($tx['confirmations'] ?? 0) : null;
        } catch (RpcException) {
            return null;
        }
    }

    /**
     * Live mempool view of a transaction's own size/fee plus its unconfirmed
     * ancestor package totals (vsize/fee summed over itself and everything
     * it still depends on) -- used by FeeBumper to size a bump for the
     * whole package (CPFP-style) instead of just this transaction's own
     * small vsize. Null if the node no longer has it in mempool (already
     * confirmed, or evicted).
     */
    public function getMempoolEntry(string $txid): ?array
    {
        try {
            $entry = $this->rpc->call('getmempoolentry', [$txid]);
            return is_array($entry) ? $entry : null;
        } catch (RpcException) {
            return null;
        }
    }

    /**
     * Unlocks the wallet for at most a few seconds, runs $fn, and always
     * relocks afterward -- shared by send() and bumpFee() so both payout
     * paths keep the wallet unlocked for the minimum time needed.
     */
    private function withUnlockedWallet(callable $fn): mixed
    {
        $s = Db::getAllSettings();
        $passphraseEnc = $s['wallet_pass_enc'] ?? '';
        if ($passphraseEnc === '') {
            throw new \RuntimeException('Wallet passphrase not configured');
        }
        $passphrase = Crypto::decrypt($passphraseEnc, 'wallet_pass');

        try {
            $this->rpc->call('walletpassphrase', [$passphrase, 10]);
        } catch (RpcException $e) {
            if (stripos($e->getMessage(), 'already unlocked') === false
                && stripos($e->getMessage(), 'running with an unencrypted wallet') === false) {
                throw $e;
            }
        }

        try {
            return $fn();
        } finally {
            try { $this->rpc->call('walletlock', []); } catch (\Throwable) {}
        }
    }

    public function getBalance(): string
    {
        $v = $this->rpc->call('getbalance', []);
        return (string)$v;
    }

    public function testConnection(): array
    {
        $info = $this->rpc->call('getwalletinfo', []);
        return is_array($info) ? $info : [];
    }
}
