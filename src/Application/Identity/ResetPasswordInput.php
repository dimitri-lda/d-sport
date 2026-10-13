<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class ResetPasswordInput
{
    public function __construct(
        public string $email,
        public string $token,
        public string $password,
        public string $passwordConfirmation,
    ) {
    }
}
