<?php

declare(strict_types=1);

namespace App\Application\Identity;

/** Shared password strength rule for register, reset and change flows. */
final class PasswordPolicy
{
    private const MIN_LENGTH = 10;
    private const MAX_LENGTH = 255;

    /** @return list<string> stable user-facing violation messages */
    public static function violations(string $password): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = 'The password must be at least 10 characters long.';
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = 'The password must be at most 255 characters long.';
        }
        if (1 !== preg_match('/[A-Z]/', $password)) {
            $errors[] = 'The password must contain at least one uppercase letter.';
        }
        if (1 !== preg_match('/[a-z]/', $password)) {
            $errors[] = 'The password must contain at least one lowercase letter.';
        }
        if (1 !== preg_match('/[0-9]/', $password)) {
            $errors[] = 'The password must contain at least one digit.';
        }
        if (1 !== preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'The password must contain at least one symbol.';
        }

        return $errors;
    }
}
