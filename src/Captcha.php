<?php
declare(strict_types=1);

namespace ElektronFaucet;

final class Captcha
{
    public static function siteKey(): string
    {
        return (string)Db::getSetting('hcaptcha_site', '');
    }

    public static function isEnabled(): bool
    {
        $s = Db::getAllSettings();
        return !empty($s['hcaptcha_site']) && !empty($s['hcaptcha_secret_enc']);
    }

    public static function verify(string $token, string $remoteIp): bool
    {
        if ($token === '') return false;
        $secretEnc = (string)Db::getSetting('hcaptcha_secret_enc', '');
        if ($secretEnc === '') return false;
        try {
            $secret = Crypto::decrypt($secretEnc, 'hcaptcha_secret');
        } catch (\Throwable) {
            return false;
        }

        $ch = curl_init('https://hcaptcha.com/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $remoteIp,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err !== 0 || !is_string($body)) return false;

        $data = json_decode($body, true);
        return is_array($data) && !empty($data['success']);
    }
}
