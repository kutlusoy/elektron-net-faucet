<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * Shared install-time logic used by both the browser wizard
 * (public/install.php) and the non-interactive Docker bootstrap
 * (bin/docker-install.php), so the two never drift apart.
 */
final class Installer
{
    /** Where config.php lives — same resolution Bootstrap::init() uses for reading. */
    public static function configPath(): string
    {
        return getenv('FAUCET_CONFIG') ?: dirname(__DIR__) . '/config.php';
    }

    /**
     * The install lock file always lives next to config.php, so it moves
     * together with it when FAUCET_CONFIG points at a mounted volume
     * (Docker) instead of the project root (bare-metal installs).
     */
    public static function lockFilePath(): string
    {
        return dirname(self::configPath()) . '/.installed';
    }

    /** @param array<string,mixed> $cfg */
    public static function writeConfigFile(string $path, array $cfg): void
    {
        $body = "<?php\nreturn [\n";
        foreach ($cfg as $k => $v) {
            $body .= '    ' . var_export($k, true) . ' => ' . var_export($v, true) . ",\n";
        }
        $body .= "];\n";
        if (file_put_contents($path, $body) === false) {
            throw new \RuntimeException('failed to write config file: ' . $path);
        }
        @chmod($path, 0600);
    }

    public static function applySchema(): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
        if ($schema === false) {
            throw new \RuntimeException('sql/schema.sql not found');
        }
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
            if ($stmt === '' || str_starts_with($stmt, '--')) continue;
            Db::pdo()->exec($stmt);
        }
    }

    public static function createAdmin(string $username, string $password): void
    {
        Db::exec(
            'INSERT INTO admin_users (username, password_hash) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)',
            [$username, password_hash($password, PASSWORD_ARGON2ID)]
        );
    }

    /** @param array<string,?string> $values */
    public static function seedDefaultSettings(array $values): void
    {
        foreach ($values as $k => $v) {
            Db::setSetting($k, $v);
        }
    }

    public static function markInstalled(string $lockFile): void
    {
        file_put_contents($lockFile, date('c'));
        @chmod($lockFile, 0600);
    }
}
