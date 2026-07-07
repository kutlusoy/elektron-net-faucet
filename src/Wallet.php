<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * Faucet payout flow:
 *   walletpassphrase(pw, 10) -> sendtoaddress -> walletlock
 * Wallet stays unlocked for at most a few seconds, only long enough for one tx.
 */
final class Wallet
{
    // 1 lep/vB -- same reasoning and value as the pool's
    // DEFAULT_FALLBACK_FEE_RATE_SATS_PER_VB (wallet-rpc.service.ts): a young
    // chain has no estimatesmartfee history yet, and -fallbackfee is
    // disabled by default, so a plain sendtoaddress fails outright with
    // "Fee estimation failed. Fallbackfee is disabled." instead of
    // guessing. Retried once with fee_rate set explicitly.
    private const FALLBACK_FEE_RATE_SATS_PER_VB = 1;

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
            $txid = $this->sendToAddressWithFeeFallback($address, $amountElek, $comment);
            if (!is_string($txid) || strlen($txid) !== 64) {
                throw new \RuntimeException('sendtoaddress returned unexpected result');
            }
            return $txid;
        } finally {
            try { $this->rpc->call('walletlock', []); } catch (\Throwable) {}
        }
    }

    private function sendToAddressWithFeeFallback(string $address, string $amountElek, string $comment): mixed
    {
        try {
            return $this->rpc->call('sendtoaddress', [$address, $amountElek, $comment, '', false]);
        } catch (RpcException $e) {
            if (stripos($e->getMessage(), 'fee estimation failed') === false) {
                throw $e;
            }
            // 4 nulls fill replaceable/conf_target/estimate_mode/avoid_reuse
            // between subtractfeefromamount and fee_rate (see sendtoaddress()
            // in src/wallet/rpc/spend.cpp) -- a positional null means "not
            // provided" for optional RPC args.
            return $this->rpc->call('sendtoaddress', [
                $address, $amountElek, $comment, '', false,
                null, null, null, null,
                self::FALLBACK_FEE_RATE_SATS_PER_VB,
            ]);
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
