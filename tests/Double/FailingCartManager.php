<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Cart\CartManager;
use App\Domain\Identity\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Test-env CartManager (config/services_test.yaml). Delegates every call to
 * the real implementation except mergeGuestIntoUser once failNextMerge()
 * has armed it, so functional tests can prove a merge failure does not
 * break login or registration.
 */
final class FailingCartManager extends CartManager
{
    private static bool $failNextMerge = false;

    public static function failNextMerge(): void
    {
        self::$failNextMerge = true;
    }

    public function mergeGuestIntoUser(SessionInterface $session, User $user): void
    {
        if (self::$failNextMerge) {
            self::$failNextMerge = false;
            throw new \RuntimeException('Injected guest-cart merge failure.');
        }
        parent::mergeGuestIntoUser($session, $user);
    }
}
