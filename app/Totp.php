
<?php

final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 20): string
    {
        $random = random_bytes($length);

        return self::base32Encode($random);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';

        foreach (str_split($data) as $char) {
            $bits .= str_pad(
                decbin(ord($char)),
                8,
                '0',
                STR_PAD_LEFT
            );
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }

            $encoded .= self::BASE32[bindec($chunk)];
        }

        return $encoded;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(
            str_replace(' ', '', trim($secret))
        );

        $secret = rtrim($secret, '=');

        if ($secret === '') {
            throw new InvalidArgumentException(
                'Authenticator secret cannot be empty.'
            );
        }

        $bits = '';

        foreach (str_split($secret) as $char) {
            $position = strpos(self::BASE32, $char);

            if ($position === false) {
                throw new InvalidArgumentException(
                    'Invalid authenticator secret.'
                );
            }

            $bits .= str_pad(
                decbin($position),
                5,
                '0',
                STR_PAD_LEFT
            );
        }

        $binary = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }

        return $binary;
    }

    public static function code(
        string $secret,
        ?int $timestamp = null
    ): string {
        $timestamp ??= time();

        $counter = intdiv($timestamp, 30);

        $key = self::base32Decode($secret);

        $binaryCounter = pack(
            'N2',
            intdiv($counter, 4294967296),
            $counter % 4294967296
        );

        $hash = hash_hmac(
            'sha1',
            $binaryCounter,
            $key,
            true
        );

        $offset = ord($hash[19]) & 0x0F;

        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad(
            (string) ($value % 1000000),
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    public static function verify(
        string $secret,
        string $code,
        ?int $timestamp = null
    ): bool {
        $code = trim($code);

        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestamp ??= time();

        foreach ([-1, 0, 1] as $window) {
            $expected = self::code(
                $secret,
                $timestamp + ($window * 30)
            );

            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }
}
