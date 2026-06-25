<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Bootstrap
{
    public static function init(): void
    {
        ini_set('display_errors', '0');
        error_reporting(E_ALL);

        spl_autoload_register(static function (string $class): void {
            $prefix = 'ElektronFaucet\\';
            if (!str_starts_with($class, $prefix)) return;
            $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) require_once $file;
        });

        $configPath = getenv('FAUCET_CONFIG') ?: dirname(__DIR__) . '/config.php';
        if (!is_file($configPath)) {
            $configPath = dirname(__DIR__) . '/config.example.php';
        }
        Config::load($configPath);

        $defaultLang = null;
        try {
            Db::migrate();                          // idempotent — safe on every request
            $defaultLang = Db::getSetting('default_lang', 'en');
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
}
