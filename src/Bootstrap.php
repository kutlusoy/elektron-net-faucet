<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Bootstrap
{
    public static function init(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        require_once __DIR__ . '/Autoload.php';
        Autoload::register();

        $configPath = getenv('FAUCET_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($configPath)) {
            $configPath = dirname(__DIR__) . '/config.example.php';
        }
        Config::load($configPath);

        $defaultLang = null;
        try {
            Db::migrate();                          // idempotent — safe on every request
            $defaultLang = Db::getSetting('default_lang', 'en');
            self::checkOnChainCapability();
        } catch (\Throwable) {}
        I18n::boot($defaultLang ?? 'en');

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: same-origin');
            if (!empty($_SERVER['HTTPS'])) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    }

    /**
     * Non-fatal, heavily throttled check that the configured node RPC
     * supports `scantxoutset` -- the RPC the whole on-chain anti-abuse
     * check (Wallet::addressHasOnChainBalance) depends on. Never blocks a
     * request: on failure it just writes an audit_log entry so an operator
     * notices in the Admin Panel instead of claims silently going
     * unverified. Only probes `help scantxoutset` (cheap, no UTXO set scan)
     * and only once per interval, so this stays negligible on the hot path
     * every other request hits.
     */
    private static function checkOnChainCapability(int $minIntervalSeconds = 900): void
    {
        $last = (int)Db::getSetting('scantxoutset_check_at', '0');
        if (time() - $last < $minIntervalSeconds) {
            return;
        }
        Db::setSetting('scantxoutset_check_at', (string)time());

        try {
            // Core answers `help <unknown command>` with a 200 OK string
            // like "help: unknown command: X" rather than an RPC error, so
            // the *absence* of that phrase is the actual success signal --
            // a real command's help text never contains it.
            $help = Wallet::fromSettings()->rpc()->call('help', ['scantxoutset']);
            if (!is_string($help) || stripos($help, 'unknown command') !== false) {
                throw new \RuntimeException('scantxoutset not recognized by node: ' . (is_string($help) ? $help : 'non-string help response'));
            }
        } catch (\Throwable $e) {
            Logger::audit(null, 'scantxoutset_unavailable', ['error' => $e->getMessage()]);
        }
    }
}
