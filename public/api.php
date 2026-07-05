<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
\ElektronFaucet\Bootstrap::init();

use ElektronFaucet\Db;
use ElektronFaucet\Csrf;
use ElektronFaucet\Captcha;
use ElektronFaucet\AddressValidator;
use ElektronFaucet\RateLimiter;
use ElektronFaucet\Wallet;
use ElektronFaucet\RpcException;
use ElektronFaucet\Logger;
use ElektronFaucet\Flash;

// Plain POST/redirect handler. The claim form on index.php submits here,
// the result lands in a flash cookie, and the browser redirects back to
// index.php so the result is shown without any JavaScript.

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

$back = static function (bool $ok, string $msg, ?string $txid = null): never {
    $payload = $msg;
    if ($ok && $txid !== null && $txid !== '') {
        // Persist the txid through the redirect so the success page can
        // turn it into an explorer link.
        $payload .= "\0" . $txid;
    }
    Flash::set($ok, $payload);
    header('Location: index.php');
    exit;
};

if ($method !== 'POST') {
    $back(false, __('err.method'));
}

$csrf = (string)($_POST['csrf'] ?? '');
if (!Csrf::check($csrf)) {
    $back(false, __('err.csrf'));
}

$address = trim((string)($_POST['address'] ?? ''));
if ($address === '' || strlen($address) > 90 || !AddressValidator::isValid($address, 'be')) {
    $back(false, __('err.invalid_address'));
}

if (Captcha::isEnabled()) {
    $token = (string)($_POST['h-captcha-response'] ?? '');
    if (!Captcha::verify($token, $ip)) {
        $back(false, __('err.captcha'));
    }
}

// Serialize check-then-insert with a session-scoped advisory lock so two
// concurrent requests (same address, or different addresses racing the
// shared hourly/daily budget) can't both pass RateLimiter::check() before
// either has committed a 'pending' claims row. Only the fast decide+insert
// step is held under the lock — the slow part (actual RPC payout) runs
// after it's released. If MySQL's connection to this request ever dies
// unexpectedly, the lock is released automatically when the session ends,
// so a stuck lock can't outlive the request that took it.
$lockAcquired = (int)Db::fetchValue("SELECT GET_LOCK('elek_faucet_claim', 10)");
if ($lockAcquired !== 1) {
    $back(false, __('err.busy'));
}

try {
    $err = RateLimiter::check($address, $ip);
    if ($err !== null) {
        Db::exec("SELECT RELEASE_LOCK('elek_faucet_claim')");
        $back(false, $err);
    }

    $s          = Db::getAllSettings();
    $amountElek = (string)($s['amount_elek'] ?? '0');
    $amountSat  = RateLimiter::elekToSat($amountElek);
    if ($amountSat <= 0) {
        Db::exec("SELECT RELEASE_LOCK('elek_faucet_claim')");
        $back(false, __('err.disabled'));
    }

    Db::exec(
        "INSERT INTO claims (address, ip, amount_satoshi, status) VALUES (?, ?, ?, 'pending')",
        [$address, RateLimiter::ipToBin($ip), $amountSat]
    );
    $claimId = (int)Db::lastInsertId();
} finally {
    Db::exec("SELECT RELEASE_LOCK('elek_faucet_claim')");
}

try {
    $wallet = Wallet::fromSettings();
    $txid   = $wallet->send($address, $amountElek, 'faucet');
    Db::exec(
        "UPDATE claims SET status = 'sent', txid = ?, sent_at = NOW() WHERE id = ?",
        [$txid, $claimId]
    );
    Logger::audit(null, 'claim_sent', ['claim_id' => $claimId, 'address' => $address, 'txid' => $txid]);
    $back(true, __('faucet.success'), $txid);
} catch (RpcException $e) {
    // Bitcoin-Core JSON-RPC error code -6 = "Insufficient funds" — the one
    // failure mode a visitor can actually do something about (donate).
    Db::exec(
        "UPDATE claims SET status = 'failed', error = ? WHERE id = ?",
        [substr($e->getMessage(), 0, 1000), $claimId]
    );
    Logger::audit(null, 'claim_failed', ['claim_id' => $claimId, 'address' => $address, 'error' => $e->getMessage()]);
    $back(false, $e->getCode() === -6 ? __('err.insufficient_funds') : __('err.payout_failed'));
} catch (\Throwable $e) {
    // Everything else (RPC unreachable, wrong wallet passphrase, fee
    // estimation failure, ...) stays generic on purpose — don't leak node
    // internals to the visitor.
    Db::exec(
        "UPDATE claims SET status = 'failed', error = ? WHERE id = ?",
        [substr($e->getMessage(), 0, 1000), $claimId]
    );
    Logger::audit(null, 'claim_failed', ['claim_id' => $claimId, 'address' => $address, 'error' => $e->getMessage()]);
    $back(false, __('err.payout_failed'));
}
