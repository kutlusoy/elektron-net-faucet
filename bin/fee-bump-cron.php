<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Bootstrap.php';
\ElektronFaucet\Bootstrap::init();

use ElektronFaucet\Db;
use ElektronFaucet\FeeBumper;
use ElektronFaucet\Logger;
use ElektronFaucet\Wallet;

// Ticked every ~60s from docker/entrypoint.sh; self-throttles against the
// admin-configurable `fee_bump_interval_min` setting (Admin Panel ->
// Mempool & Fee Bumping) so the effective interval can be changed at
// runtime without touching this container's process supervision.
$intervalMin = max(1, (int)Db::getSetting('fee_bump_interval_min', '10'));
$lastRunAt   = (int)Db::getSetting('fee_bump_last_run_at', '0');
if (time() - $lastRunAt < $intervalMin * 60) {
    exit(0);
}
Db::setSetting('fee_bump_last_run_at', (string)time());

try {
    $wallet  = Wallet::fromSettings();
    $summary = FeeBumper::sweepStuckClaims($wallet, $intervalMin);
    if ($summary['checked'] > 0) {
        Logger::audit(null, 'fee_bump_cron', $summary);
    }
} catch (\Throwable $e) {
    Logger::audit(null, 'fee_bump_cron_error', ['error' => $e->getMessage()]);
}
