
<?php

// Load the private local configuration when running on XAMPP.
$localMfaConfig = 'C:/xampp/private/mfa_config.php';

if (file_exists($localMfaConfig)) {
    require_once $localMfaConfig;
}

// On Render, load the encryption key from an environment variable.
if (!defined('APP_MFA_ENCRYPTION_KEY')) {
    $mfaKey = getenv('APP_MFA_ENCRYPTION_KEY');

    if ($mfaKey !== false && $mfaKey !== '') {
        define('APP_MFA_ENCRYPTION_KEY', $mfaKey);
    }
}

final class MfaSecurity
{
    private static function encryptionKey(): string
    {
        if (!defined('APP_MFA_ENCRYPTION_KEY')) {
            throw new RuntimeException('MFA encryption key is not configured.');
        }

        $key = base64_decode(APP_MFA_ENCRYPTION_KEY, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Invalid MFA encryption key.');
        }

        return $key;
    }

    public static function encrypt(string $plainText): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $encrypted = openssl_encrypt(
            $plainText,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new RuntimeException('MFA encryption failed.');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $encoded): string
    {
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < 28) {
            throw new RuntimeException('Invalid encrypted MFA secret.');
        }

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);

        $decrypted = openssl_decrypt(
            $encrypted,
            'aes-256-gcm',
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new RuntimeException('MFA decryption failed.');
        }

        return $decrypted;
    }
}
