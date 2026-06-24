<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * AES-256-GCM symmetric encryption for at-rest secrets stored in DB.
 * Key derived from Config::get('app_key') (hex string), HKDF-mixed with a context label.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(string $context): string
    {
        $raw = (string)Config::get('app_key', '');
        $key = ctype_xdigit($raw) ? @hex2bin($raw) : $raw;
        if ($key === false || strlen($key) < 32) {
            $key = hash('sha256', $raw, true);
        }
        return hash_hkdf('sha256', $key, 32, 'elek-faucet:' . $context);
    }

    public static function encrypt(string $plain, string $context = 'default'): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, self::CIPHER, self::key($context), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('encryption failed');
        }
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $payload, string $context = 'default'): string
    {
        if (!str_starts_with($payload, 'v1:')) {
            throw new \RuntimeException('unknown ciphertext format');
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 12 + 16 + 1) {
            throw new \RuntimeException('invalid ciphertext');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct = substr($raw, 28);
        $pt = openssl_decrypt($ct, self::CIPHER, self::key($context), OPENSSL_RAW_DATA, $iv, $tag);
        if ($pt === false) {
            throw new \RuntimeException('decryption failed');
        }
        return $pt;
    }
}
