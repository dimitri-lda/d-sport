<?php

declare(strict_types=1);

namespace App\Infrastructure\Crypto;

/**
 * Versioned authenticated encryption for sensitive payment columns.
 *
 * Format: `v1.<base64url(nonce || ciphertext)>` where the nonce is
 * SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES random bytes and the
 * context string is bound as additional authenticated data (AAD). A ciphertext
 * written for one table/field/row context cannot be replayed under another.
 */
final class SodiumFieldCipher implements FieldCipher
{
    private const VERSION_PREFIX = 'v1.';
    private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    private readonly string $key;

    public function __construct(string $key)
    {
        if (self::keyBytes() !== \strlen($key)) {
            throw new \InvalidArgumentException(\sprintf('Field cipher key must be exactly %d bytes once decoded.', self::keyBytes()));
        }
        $this->key = $key;
    }

    public static function keyBytes(): int
    {
        return SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $context, $nonce, $this->key);

        return self::VERSION_PREFIX.$this->encode($nonce.$ciphertext);
    }

    public function decrypt(string $ciphertext, string $context): string
    {
        if (!str_starts_with($ciphertext, self::VERSION_PREFIX)) {
            throw new \RuntimeException('Unsupported field cipher format.');
        }
        $binary = $this->decode(substr($ciphertext, \strlen(self::VERSION_PREFIX)));
        if (\strlen($binary) <= self::NONCE_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new \RuntimeException('Malformed field cipher payload.');
        }
        $nonce = substr($binary, 0, self::NONCE_BYTES);
        $sealed = substr($binary, self::NONCE_BYTES);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($sealed, $context, $nonce, $this->key);
        if (false === $plaintext) {
            throw new \RuntimeException('Field cipher decryption failed: wrong key or context.');
        }

        return $plaintext;
    }

    private function encode(string $binary): string
    {
        return sodium_bin2base64($binary, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private function decode(string $encoded): string
    {
        try {
            return sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (\SodiumException $e) {
            throw new \RuntimeException('Malformed field cipher payload.', 0, $e);
        }
    }
}
