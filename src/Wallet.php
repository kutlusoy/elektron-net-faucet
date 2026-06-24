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
        return new self(new RpcClient($host, $port, $user, $pass, $wallet === '' ? null : $wallet));
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
            $txid = $this->rpc->call('sendtoaddress', [
                $address,
                $amountElek,
                $comment,
                '',
                false,
            ]);
            if (!is_string($txid) || strlen($txid) !== 64) {
                throw new \RuntimeException('sendtoaddress returned unexpected result');
            }
            return $txid;
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
