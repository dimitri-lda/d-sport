<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Application\Identity\PasswordConfirmationService;
use App\Domain\Identity\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(PasswordConfirmationService::class)]
final class PasswordConfirmationServiceTest extends TestCase
{
    private UserPasswordHasherInterface $hasher;
    private PasswordConfirmationService $service;
    private Session $session;
    private User $user;

    protected function setUp(): void
    {
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->service = new PasswordConfirmationService($this->hasher);
        $this->session = new Session(new MockArraySessionStorage());
        $this->user = new User('customer@example.test', 'hash', 'Customer');
    }

    private static function at(int $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$timestamp);
    }

    public function testConfirmationValidForExactlyThreeHours(): void
    {
        $this->hasher->method('isPasswordValid')->willReturn(true);
        $now = self::at(1000000);
        self::assertTrue($this->service->confirm($this->user, 'secret', $this->session, $now));

        self::assertTrue($this->service->isConfirmed($this->session, $now));
        self::assertTrue($this->service->isConfirmed($this->session, self::at(1000000 + 10800)));
        self::assertFalse($this->service->isConfirmed($this->session, self::at(1000000 + 10801)));
    }

    public function testConfirmWithWrongPasswordLeavesNoMarker(): void
    {
        $this->hasher->method('isPasswordValid')->willReturn(false);
        $now = self::at(1000000);

        self::assertFalse($this->service->confirm($this->user, 'wrong', $this->session, $now));
        self::assertNull($this->service->confirmedAt($this->session));
        self::assertFalse($this->service->isConfirmed($this->session, $now));
    }

    public function testMissingOrFutureMarkerIsNotConfirmed(): void
    {
        $now = self::at(1000000);
        self::assertFalse($this->service->isConfirmed($this->session, $now));

        $this->session->set(PasswordConfirmationService::SESSION_KEY, 'not-an-int');
        self::assertFalse($this->service->isConfirmed($this->session, $now));

        $this->session->set(PasswordConfirmationService::SESSION_KEY, 1000060);
        self::assertFalse($this->service->isConfirmed($this->session, $now));
    }
}
