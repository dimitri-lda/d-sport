<?php

declare(strict_types=1);

namespace App\Infrastructure\Crypto;

interface FieldCipher
{
    public function encrypt(string $plaintext, string $context): string;

    public function decrypt(string $ciphertext, string $context): string;
}
