<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Confirm your password" marker kept in the session. A confirmation is
 * honored for three hours; POSTing the password again refreshes it.
 */
final class PasswordConfirmationService
{
    public const SESSION_KEY = 'auth.password_confirmed_at';
    public const TTL_SECONDS = 10800;

    public function __construct(private readonly UserPasswordHasherInterface $hasher)
    {
    }

    public function isConfirmed(SessionInterface $session, \DateTimeImmutable $now): bool
    {
        $confirmedAt = $session->get(self::SESSION_KEY);
        if (!\is_int($confirmedAt)) {
            return false;
        }
        $age = $now->getTimestamp() - $confirmedAt;

        return $age >= 0 && $age <= self::TTL_SECONDS;
    }

    public function confirmedAt(SessionInterface $session): ?int
    {
        $confirmedAt = $session->get(self::SESSION_KEY);

        return \is_int($confirmedAt) ? $confirmedAt : null;
    }

    /** Verifies the password and stamps the marker; false on bad password. */
    public function confirm(User $user, string $password, SessionInterface $session, \DateTimeImmutable $now): bool
    {
        if (!$this->hasher->isPasswordValid($user, $password)) {
            return false;
        }
        $session->set(self::SESSION_KEY, $now->getTimestamp());

        return true;
    }
}
