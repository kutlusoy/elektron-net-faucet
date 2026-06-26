<?php
declare(strict_types=1);

namespace ElektronFaucet;

// Simple cookie-backed flash messages for the POST→redirect→GET pattern.
// We don't run PHP sessions in this app (Auth uses its own DB-backed
// session) and we explicitly don't want result text in the URL bar, so a
// short-lived signed cookie is the smallest thing that works.
final class Flash
{
    private const COOKIE = 'fct_flash';
    private const TTL    = 30; // seconds

    public static function set(bool $ok, string $msg): void
    {
        $payload = json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }
        setcookie(self::COOKIE, $payload, [
            'expires'  => time() + self::TTL,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $payload;
    }

    /**
     * Returns the flash payload once and clears the cookie.
     * @return array{ok:bool,msg:string}|null
     */
    public static function take(): ?array
    {
        if (empty($_COOKIE[self::COOKIE])) {
            return null;
        }
        $raw = (string)$_COOKIE[self::COOKIE];
        unset($_COOKIE[self::COOKIE]);
        setcookie(self::COOKIE, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['ok'], $data['msg'])) {
            return null;
        }
        return [
            'ok'  => (bool)$data['ok'],
            'msg' => (string)$data['msg'],
        ];
    }
}
