<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Autoload
{
    public static function register(): void
    {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'ElektronFaucet\\';
            if (!str_starts_with($class, $prefix)) return;
            $file = __DIR__ . '/' . substr($class, strlen($prefix)) . '.php';
            if (is_file($file)) require_once $file;
        });
    }
}
