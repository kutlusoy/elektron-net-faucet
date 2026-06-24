<?php
declare(strict_types=1);

namespace ElektronFaucet;

/**
 * Bech32 (BIP-173) + Bech32m (BIP-350) validator for Elektron Net (HRP = "be").
 * Accepts witness v0 (be1q..., 20- or 32-byte program) and v1+ (be1p..., 32-byte program for v1).
 */
final class AddressValidator
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const BECH32_CONST  = 1;
    private const BECH32M_CONST = 0x2bc830a3;

    public static function isValid(string $addr, string $expectedHrp = 'be'): bool
    {
        $a = strtolower(trim($addr));
        if ($a === '' || strlen($a) > 90) return false;
        if ($a !== $addr && strtoupper($addr) !== $addr) return false;

        $pos = strrpos($a, '1');
        if ($pos === false || $pos < 1 || $pos + 7 > strlen($a)) return false;

        $hrp = substr($a, 0, $pos);
        if ($hrp !== $expectedHrp) return false;

        $dataPart = substr($a, $pos + 1);
        $data = [];
        foreach (str_split($dataPart) as $ch) {
            $idx = strpos(self::CHARSET, $ch);
            if ($idx === false) return false;
            $data[] = $idx;
        }
        if (count($data) < 6) return false;

        $witVer = $data[0];
        if ($witVer > 16) return false;

        $expectedConst = $witVer === 0 ? self::BECH32_CONST : self::BECH32M_CONST;
        if (self::polymod(array_merge(self::hrpExpand($hrp), $data)) !== $expectedConst) {
            return false;
        }

        $program = self::convertBits(array_slice($data, 1, count($data) - 7), 5, 8, false);
        if ($program === null) return false;
        $plen = count($program);

        if ($witVer === 0) {
            return $plen === 20 || $plen === 32;
        }
        if ($witVer === 1) {
            return $plen === 32;
        }
        return $plen >= 2 && $plen <= 40;
    }

    private static function hrpExpand(string $hrp): array
    {
        $hi = [];
        $lo = [];
        for ($i = 0, $n = strlen($hrp); $i < $n; $i++) {
            $hi[] = ord($hrp[$i]) >> 5;
            $lo[] = ord($hrp[$i]) & 31;
        }
        return array_merge($hi, [0], $lo);
    }

    private static function polymod(array $values): int
    {
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($b >> $i) & 1) $chk ^= $gen[$i];
            }
        }
        return $chk;
    }

    private static function convertBits(array $data, int $from, int $to, bool $pad): ?array
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $to) - 1;
        $maxAcc = (1 << ($from + $to - 1)) - 1;
        foreach ($data as $v) {
            if ($v < 0 || ($v >> $from) !== 0) return null;
            $acc = (($acc << $from) | $v) & $maxAcc;
            $bits += $from;
            while ($bits >= $to) {
                $bits -= $to;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad) {
            if ($bits > 0) $ret[] = ($acc << ($to - $bits)) & $maxv;
        } else {
            if ($bits >= $from || (($acc << ($to - $bits)) & $maxv) !== 0) return null;
        }
        return $ret;
    }
}
