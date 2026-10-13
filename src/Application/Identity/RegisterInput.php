<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class RegisterInput
{
    public function __construct(
        public string $displayName,
        public string $email,
        public string $password,
        public string $passwordConfirmation,
        public bool $acceptedTerms,
    ) {
    }
}
