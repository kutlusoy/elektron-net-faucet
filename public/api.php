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

$err = RateLimiter::check($address, $ip);
if ($err !== null) {
    $back(false, $err);
}

$s          = Db::getAllSettings();
$amountElek = (string)($s['amount_elek'] ?? '0');
$amountSat  = RateLimiter::elekToSat($amountElek);
if ($amountSat <= 0) {
    $back(false, __('err.disabled'));
}

Db::exec(
    "INSERT INTO claims (address, ip, amount_satoshi, status) VALUES (?, ?, ?, 'pending')",
    [$address, RateLimiter::ipToBin($ip), $amountSat]
);
$claimId = (int)Db::lastInsertId();

try {
    $wallet = Wallet::fromSettings();
    $txid   = $wallet->send($address, $amountElek, 'faucet');
    Db::exec(
        "UPDATE claims SET status = 'sent', txid = ?, sent_at = NOW() WHERE id = ?",
        [$txid, $claimId]
    );
    Logger::audit(null, 'claim_sent', ['claim_id' => $claimId, 'address' => $address, 'txid' => $txid]);
    $back(true, __('faucet.success'), $txid);
} catch (\Throwable $e) {
    Db::exec(
        "UPDATE claims SET status = 'failed', error = ? WHERE id = ?",
        [substr($e->getMessage(), 0, 1000), $claimId]
    );
    Logger::audit(null, 'claim_failed', ['claim_id' => $claimId, 'address' => $address, 'error' => $e->getMessage()]);
    $back(false, __('err.payout_failed'));
}
