<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Infrastructure\Crypto\SodiumFieldCipher;
use PHPUnit\Framework\TestCase;

final class SodiumFieldCipherTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef'; // exactly 32 bytes, test-only

    private SodiumFieldCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new SodiumFieldCipher(self::KEY);
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $ciphertext = $this->cipher->encrypt('secret-payload', 'payment_attempts.payload_encrypted.abc');

        self::assertStringStartsWith('v1.', $ciphertext);
        self::assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]+$/', $ciphertext);
        self::assertSame(
            'secret-payload',
            $this->cipher->decrypt($ciphertext, 'payment_attempts.payload_encrypted.abc'),
        );
    }

    public function testCiphertextIsRandomPerCall(): void
    {
        $one = $this->cipher->encrypt('same', 'ctx');
        $two = $this->cipher->encrypt('same', 'ctx');

        self::assertNotSame($one, $two);
        self::assertSame('same', $this->cipher->decrypt($one, 'ctx'));
        self::assertSame('same', $this->cipher->decrypt($two, 'ctx'));
    }

    public function testDecryptWithWrongContextFails(): void
    {
        $ciphertext = $this->cipher->encrypt('secret-payload', 'payment_attempts.payload_encrypted.abc');

        $this->expectException(\RuntimeException::class);
        $this->cipher->decrypt($ciphertext, 'payment_sessions.receipt_token_encrypted.abc');
    }

    public function testDecryptWithWrongKeyFails(): void
    {
        $ciphertext = $this->cipher->encrypt('secret-payload', 'ctx');
        $other = new SodiumFieldCipher('abcdef0123456789abcdef0123456789');

        $this->expectException(\RuntimeException::class);
        $other->decrypt($ciphertext, 'ctx');
    }

    public function testTamperedPayloadFails(): void
    {
        $ciphertext = $this->cipher->encrypt('secret-payload', 'ctx');
        $binary = sodium_base642bin(substr($ciphertext, 3), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $binary[10] = 'a' === $binary[10] ? 'b' : 'a';
        $tampered = 'v1.'.sodium_bin2base64($binary, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        $this->expectException(\RuntimeException::class);
        $this->cipher->decrypt($tampered, 'ctx');
    }

    public function testKeyMustBeExactlyKeyBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SodiumFieldCipher('too-short');
    }

    public function testUnversionedPayloadFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cipher->decrypt('plain-base64-value', 'ctx');
    }
}
