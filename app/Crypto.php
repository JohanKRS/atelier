<?php
declare(strict_types=1);
namespace Studio;

final class Crypto
{
    public static function seal(string $plain, string $key, string $context): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $context, $nonce, $key);
    }

    public static function open(string $cipher, string $key, string $context): string
    {
        $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($cipher) < $n + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \RuntimeException(t('Invalid encrypted content.'));
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($cipher, $n), $context, substr($cipher, 0, $n), $key);
        if ($plain === false) {
            throw new \RuntimeException(t('Could not unlock the content.'));
        }
        return $plain;
    }

    public static function derive(string $password, string $salt): string
    {
        return sodium_crypto_pwhash(32, $password, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }
}
