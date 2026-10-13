<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads users through the lower(email) index so login is case-insensitive —
 * matching the uniqueness contract enforced at registration and profile
 * update. Session refresh reloads by persisted id.
 *
 * @implements UserProviderInterface<User>
 */
final class CaseInsensitiveUserProvider implements UserProviderInterface
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->users->findByEmail(trim($identifier));
        if (null === $user) {
            $exception = new UserNotFoundException('No user exists for the given email address.');
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', get_debug_type($user)));
        }
        $id = $user->getId();
        $fresh = null !== $id ? $this->users->find($id) : null;
        if (!$fresh instanceof User) {
            $exception = new UserNotFoundException('The session user could not be refreshed.');
            $exception->setUserIdentifier($user->getUserIdentifier());
            throw $exception;
        }

        return $fresh;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class || is_subclass_of($class, User::class);
    }
}
