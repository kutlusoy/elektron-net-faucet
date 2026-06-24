<?php
declare(strict_types=1);

namespace ElektronFaucet {

/**
 * Simple file-based translations. One PHP file per locale under /lang.
 * Each file returns an array<string,string> of message_id => translation.
 * Locale resolution priority:
 *   1. ?lang=xx query (and cookie set)
 *   2. fct_lang cookie
 *   3. Accept-Language header
 *   4. settings.default_lang
 *   5. 'en'
 */
final class I18n
{
    public const LOCALES = [
        'en' => 'English',
        'de' => 'Deutsch',
        'es' => 'Español',
        'it' => 'Italiano',
        'fr' => 'Français',
        'pt' => 'Português',
    ];

    private static string $locale = 'en';
    /** @var array<string,string> */
    private static array $strings = [];
    /** @var array<string,string> */
    private static array $fallback = [];
    private static bool $booted = false;

    public static function boot(?string $defaultLocale = null): void
    {
        if (self::$booted) return;
        self::$booted = true;
        self::$locale = self::resolveLocale($defaultLocale ?? 'en');
        self::$fallback = self::loadFile('en');
        self::$strings = self::$locale === 'en' ? self::$fallback : self::loadFile(self::$locale);
    }

    public static function locale(): string
    {
        return self::$locale;
    }

    public static function setLocale(string $locale): void
    {
        if (!array_key_exists($locale, self::LOCALES)) return;
        setcookie('fct_lang', $locale, [
            'expires'  => time() + 86400 * 365,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        self::$booted = false;
        $_COOKIE['fct_lang'] = $locale;
        self::boot();
    }

    public static function t(string $key, array $params = []): string
    {
        $msg = self::$strings[$key] ?? self::$fallback[$key] ?? $key;
        if ($params === []) return $msg;
        foreach ($params as $k => $v) {
            $msg = str_replace('{' . $k . '}', (string)$v, $msg);
        }
        return $msg;
    }

    private static function resolveLocale(string $default): string
    {
        $candidates = [];
        if (!empty($_GET['lang']) && is_string($_GET['lang'])) {
            $candidates[] = strtolower(substr($_GET['lang'], 0, 5));
        }
        if (!empty($_COOKIE['fct_lang']) && is_string($_COOKIE['fct_lang'])) {
            $candidates[] = strtolower(substr($_COOKIE['fct_lang'], 0, 5));
        }
        $hdr = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        if ($hdr !== '') {
            foreach (explode(',', $hdr) as $part) {
                $part = trim(explode(';', $part)[0]);
                if ($part !== '') $candidates[] = strtolower(substr($part, 0, 2));
            }
        }
        $candidates[] = strtolower(substr($default, 0, 2));
        foreach ($candidates as $c) {
            $short = substr($c, 0, 2);
            if (array_key_exists($short, self::LOCALES)) return $short;
        }
        return 'en';
    }

    /**
     * @return array<string,string>
     */
    private static function loadFile(string $locale): array
    {
        $path = dirname(__DIR__) . '/lang/' . $locale . '.php';
        if (!is_file($path)) return [];
        $data = require $path;
        return is_array($data) ? $data : [];
    }
}

} // namespace ElektronFaucet

namespace {

if (!function_exists('__')) {
    function __(string $key, array $params = []): string
    {
        return \ElektronFaucet\I18n::t($key, $params);
    }
}
if (!function_exists('he')) {
    function he(string $key, array $params = []): string
    {
        return htmlspecialchars(\ElektronFaucet\I18n::t($key, $params), ENT_QUOTES, 'UTF-8');
    }
}

} // global namespace
