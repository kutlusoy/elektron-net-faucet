<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Csrf
{
    public static function token(): string
    {
        $sess = Auth::session();
        if ($sess === null) {
            if (empty($_COOKIE['fct_csrf'])) {
                $t = bin2hex(random_bytes(32));
                setcookie('fct_csrf', $t, [
                    'expires'  => 0,
                    'path'     => '/',
                    'secure'   => !empty($_SERVER['HTTPS']),
                    'httponly' => false,
                    'samesite' => 'Strict',
                ]);
                $_COOKIE['fct_csrf'] = $t;
            }
            return (string)$_COOKIE['fct_csrf'];
        }
        return (string)$sess['csrf_token'];
    }

    public static function check(string $token): bool
    {
        $expected = self::token();
        return $expected !== '' && hash_equals($expected, $token);
    }
}
