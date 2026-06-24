<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Auth
{
    private const COOKIE = 'fct_sess';
    private const TTL_SECONDS = 7200;
    private static ?array $session = null;
    private static bool $loaded = false;

    public static function session(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $sid = (string)($_COOKIE[self::COOKIE] ?? '');
            if ($sid !== '' && preg_match('/^[a-f0-9]{64}$/', $sid)) {
                $row = Db::fetchOne(
                    'SELECT id, admin_id, data, csrf_token, expires_at FROM sessions
                     WHERE id = ? AND expires_at > NOW()',
                    [$sid]
                );
                if ($row !== null) self::$session = $row;
            }
        }
        return self::$session;
    }

    public static function requireLogin(): array
    {
        $s = self::session();
        if ($s === null) {
            header('Location: admin.php?login=1');
            exit;
        }
        return $s;
    }

    public static function login(string $username, string $password, string $ip): array
    {
        $ipBin = RateLimiter::ipToBin($ip);
        $failed = (int)Db::fetchValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip = ? AND success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            [$ipBin]
        );
        if ($failed >= 10) {
            return ['ok' => false, 'error' => 'Zu viele Fehlversuche, bitte später wieder.'];
        }

        $user = Db::fetchOne(
            'SELECT id, username, password_hash FROM admin_users WHERE username = ?',
            [$username]
        );
        $hash = $user['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$xxxxxxxxxxxxxxxxxxxxxx$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
        $ok = $user !== null && password_verify($password, (string)$hash);

        Db::exec(
            'INSERT INTO login_attempts (ip, username, success) VALUES (?, ?, ?)',
            [$ipBin, $username, $ok ? 1 : 0]
        );

        if (!$ok || $user === null) {
            return ['ok' => false, 'error' => 'Login fehlgeschlagen.'];
        }

        $sid = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        Db::exec(
            'INSERT INTO sessions (id, admin_id, csrf_token, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [$sid, (int)$user['id'], $csrf, self::TTL_SECONDS]
        );
        Db::exec('UPDATE admin_users SET last_login = NOW() WHERE id = ?', [(int)$user['id']]);

        setcookie(self::COOKIE, $sid, [
            'expires'  => time() + self::TTL_SECONDS,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        return ['ok' => true, 'admin_id' => (int)$user['id']];
    }

    public static function logout(): void
    {
        $sid = (string)($_COOKIE[self::COOKIE] ?? '');
        if ($sid !== '') {
            Db::exec('DELETE FROM sessions WHERE id = ?', [$sid]);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    }

    public static function gcSessions(): void
    {
        Db::exec('DELETE FROM sessions WHERE expires_at < NOW()');
    }
}
