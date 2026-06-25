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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'claim');
$ip     = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

$reply = static function (bool $ok, string $msg = '', array $extra = []): never {
    echo json_encode(array_merge(['ok' => $ok, 'error' => $ok ? null : $msg], $extra));
    exit;
};

// ── Donate endpoint ──────────────────────────────────────────────────────────
if ($action === 'donate') {
    if ($method !== 'POST') { http_response_code(405); $reply(false, __('err.method')); }

    $csrf = (string)($_POST['csrf'] ?? '');
    if (!Csrf::check($csrf)) { http_response_code(403); $reply(false, __('err.csrf')); }

    $amountRaw = trim((string)($_POST['donate_amount'] ?? ''));
    $amount    = (float)str_replace(',', '.', $amountRaw);
    if ($amount <= 0 || $amount > 1000000) {
        http_response_code(400);
        $reply(false, __('donate.err.amount'));
    }

    // simple rate-limit: max 10 donation reports per IP per hour
    $recentCount = (int)Db::fetchValue(
        'SELECT COUNT(*) FROM donations WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
        [RateLimiter::ipToBin($ip)]
    );
    if ($recentCount >= 10) { http_response_code(429); $reply(false, __('err.cooldown_ip', ['hours' => '1'])); }

    $name    = mb_substr(trim((string)($_POST['donor_name'] ?? '')), 0, 100);
    $message = mb_substr(trim((string)($_POST['donor_msg']  ?? '')), 0, 500);

    Db::exec(
        'INSERT INTO donations (amount_elek, donor_name, message, ip) VALUES (?, ?, ?, ?)',
        [
            number_format($amount, 8, '.', ''),
            $name !== '' ? $name : null,
            $message !== '' ? $message : null,
            RateLimiter::ipToBin($ip),
        ]
    );

    $reply(true, '', ['message' => __('donate.thanks')]);
}

// ── Claim endpoint (default) ─────────────────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => __('err.method')]);
    exit;
}

$csrf = (string)($_POST['csrf'] ?? '');
if (!Csrf::check($csrf)) {
    http_response_code(403);
    $reply(false, __('err.csrf'));
}

$address = trim((string)($_POST['address'] ?? ''));
if ($address === '' || strlen($address) > 90 || !AddressValidator::isValid($address, 'be')) {
    http_response_code(400);
    $reply(false, __('err.invalid_address'));
}

if (Captcha::isEnabled()) {
    $token = (string)($_POST['h-captcha-response'] ?? '');
    if (!Captcha::verify($token, $ip)) {
        http_response_code(400);
        $reply(false, __('err.captcha'));
    }
}

$err = RateLimiter::check($address, $ip);
if ($err !== null) {
    http_response_code(429);
    $reply(false, $err);
}

$s = Db::getAllSettings();
$amountElek = (string)($s['amount_elek'] ?? '0');
$amountSat  = RateLimiter::elekToSat($amountElek);
if ($amountSat <= 0) {
    http_response_code(503);
    $reply(false, __('err.disabled'));
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
    $reply(true, '', ['txid' => $txid]);
} catch (\Throwable $e) {
    Db::exec(
        "UPDATE claims SET status = 'failed', error = ? WHERE id = ?",
        [substr($e->getMessage(), 0, 1000), $claimId]
    );
    Logger::audit(null, 'claim_failed', ['claim_id' => $claimId, 'address' => $address, 'error' => $e->getMessage()]);
    http_response_code(502);
    $reply(false, __('err.payout_failed'));
}
