<?php
declare(strict_types=1);

/**
 * Non-interactive install for Docker deployments. Runs on every container
 * start (via docker/entrypoint.sh) but is idempotent: once .installed
 * exists, it exits immediately without touching the database, so restarts
 * stay cheap and never re-seed or clobber settings edited later via the
 * Admin Panel. This is a one-time bootstrap only — it replaces the browser
 * wizard (public/install.php) for Docker, which remains untouched for
 * bare-metal installs.
 */

require_once __DIR__ . '/../src/Autoload.php';
\ElektronFaucet\Autoload::register();

use ElektronFaucet\Config;
use ElektronFaucet\Crypto;
use ElektronFaucet\Db;
use ElektronFaucet\Installer;

function envOrNull(string $name): ?string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? null : $v;
}

$lockFile = Installer::lockFilePath();
if (is_file($lockFile)) {
    fwrite(STDOUT, "[docker-install] already installed - skipping.\n");
    exit(0);
}

$cfgPath = Installer::configPath();

if (!is_file($cfgPath)) {
    $dbName = envOrNull('FAUCET_DB_NAME');
    $dbUser = envOrNull('FAUCET_DB_USER');
    $dbPass = envOrNull('FAUCET_DB_PASS');
    // Config::load() rejects an empty db_pass just like it rejects a missing
    // one (see src/Config.php) -- require it here too instead of silently
    // generating an unusable config.php.
    if ($dbName === null || $dbUser === null || $dbPass === null) {
        fwrite(STDERR, "[docker-install] FAUCET_DB_NAME, FAUCET_DB_USER and FAUCET_DB_PASS are required.\n");
        exit(1);
    }

    @mkdir(dirname($cfgPath), 0700, true);

    Installer::writeConfigFile($cfgPath, [
        'db_host' => envOrNull('FAUCET_DB_HOST') ?? 'db',
        'db_port' => (int)(envOrNull('FAUCET_DB_PORT') ?? '3306'),
        'db_name' => $dbName,
        'db_user' => $dbUser,
        'db_pass' => $dbPass,
        // Auto-generated if not pinned in .env; persisted into the mounted
        // /config volume so it stays stable across container recreation.
        'app_key' => envOrNull('FAUCET_APP_KEY') ?? bin2hex(random_bytes(32)),
    ]);
    @chown($cfgPath, 'www-data');
    @chgrp($cfgPath, 'www-data');
}

Config::load($cfgPath);

$attempts = (int)(envOrNull('FAUCET_DB_WAIT_ATTEMPTS') ?? '30');
for ($i = 1; $i <= $attempts; $i++) {
    try {
        Db::pdo();
        break;
    } catch (\Throwable $e) {
        if ($i === $attempts) {
            fwrite(STDERR, "[docker-install] database unreachable after {$attempts} attempts: {$e->getMessage()}\n");
            exit(1);
        }
        fwrite(STDOUT, "[docker-install] waiting for database ({$i}/{$attempts})...\n");
        sleep(2);
    }
}

Installer::applySchema();

$adminUser = envOrNull('FAUCET_ADMIN_USER');
$adminPass = envOrNull('FAUCET_ADMIN_PASS');
if ($adminUser === null || $adminPass === null || strlen($adminPass) < 10) {
    fwrite(STDERR, "[docker-install] FAUCET_ADMIN_USER and FAUCET_ADMIN_PASS (>= 10 chars) are required.\n");
    exit(1);
}
Installer::createAdmin($adminUser, $adminPass);

Installer::seedDefaultSettings([
    'faucet_title'        => envOrNull('FAUCET_TITLE') ?? 'Elektron Net Faucet',
    'faucet_message'      => envOrNull('FAUCET_MESSAGE') ?? 'Claim some free ELEK!',
    'amount_elek'         => envOrNull('FAUCET_AMOUNT_ELEK') ?? '0.001',
    'daily_budget'        => envOrNull('FAUCET_DAILY_BUDGET') ?? '1',
    'hourly_budget'       => envOrNull('FAUCET_HOURLY_BUDGET') ?? '0.1',
    'per_addr_cooldown_h' => envOrNull('FAUCET_PER_ADDR_COOLDOWN_H') ?? '24',
    'per_ip_cooldown_h'   => envOrNull('FAUCET_PER_IP_COOLDOWN_H') ?? '1',
    'default_lang'        => envOrNull('FAUCET_DEFAULT_LANG') ?? 'en',
    'rpc_host'            => envOrNull('FAUCET_RPC_HOST') ?? '127.0.0.1',
    'rpc_port'            => envOrNull('FAUCET_RPC_PORT') ?? '8332',
    'rpc_user'            => envOrNull('FAUCET_RPC_USER') ?? '',
    'wallet_name'         => envOrNull('FAUCET_WALLET_NAME') ?? '',
    'sender_addr'         => envOrNull('FAUCET_SENDER_ADDR') ?? '',
    'explorer_url'        => envOrNull('FAUCET_EXPLORER_URL') ?? '',
    'hcaptcha_site'       => envOrNull('FAUCET_HCAPTCHA_SITE') ?? '',
    'rpc_tls_verify'      => envOrNull('FAUCET_RPC_TLS_VERIFY') ?? '1',
    'rpc_tls_verify_host' => envOrNull('FAUCET_RPC_TLS_VERIFY_HOST') ?? '0',
]);

// Secrets go through the exact same Crypto::encrypt() call admin.php makes,
// so Wallet::fromSettings() decrypts them identically later.
foreach ([
    ['FAUCET_RPC_PASS', 'rpc_pass_enc', 'rpc_pass'],
    ['FAUCET_WALLET_PASS', 'wallet_pass_enc', 'wallet_pass'],
    ['FAUCET_HCAPTCHA_SECRET', 'hcaptcha_secret_enc', 'hcaptcha_secret'],
] as [$env, $settingKey, $context]) {
    $v = envOrNull($env);
    if ($v !== null) {
        Db::setSetting($settingKey, Crypto::encrypt($v, $context));
    }
}

Installer::markInstalled($lockFile);

fwrite(STDOUT, "[docker-install] install successful\n");
