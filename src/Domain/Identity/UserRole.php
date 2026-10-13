<?php

declare(strict_types=1);

namespace App\Domain\Identity;

enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';
    case SuperAdmin = 'superadmin';

    public function toSecurityRole(): string
    {
        return match ($this) {
            self::Admin => 'ROLE_ADMIN',
            self::SuperAdmin => 'ROLE_SUPER_ADMIN',
            self::User => 'ROLE_USER',
        };
    }

    /** @param list<string> $roles */
    public static function fromSecurityRoles(array $roles): self
    {
        return match (true) {
            \in_array('ROLE_SUPER_ADMIN', $roles, true) => self::SuperAdmin,
            \in_array('ROLE_ADMIN', $roles, true) => self::Admin,
            default => self::User,
        };
    }
}
