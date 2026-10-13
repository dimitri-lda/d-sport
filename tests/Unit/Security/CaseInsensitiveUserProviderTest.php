<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\UserRepository;
use App\Infrastructure\Security\CaseInsensitiveUserProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

#[CoversClass(CaseInsensitiveUserProvider::class)]
final class CaseInsensitiveUserProviderTest extends TestCase
{
    private UserRepository $users;
    private CaseInsensitiveUserProvider $provider;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->provider = new CaseInsensitiveUserProvider($this->users);
    }

    public function testIdentifierIsTrimmedAndDelegated(): void
    {
        $user = new User('mixed@example.test', 'hash', 'Mixed');
        $this->users->expects(self::once())
            ->method('findByEmail')
            ->with('Mixed@Example.TEST')
            ->willReturn($user);

        self::assertSame($user, $this->provider->loadUserByIdentifier('  Mixed@Example.TEST  '));
    }

    public function testMissingUserRaisesUserNotFound(): void
    {
        $this->users->method('findByEmail')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier('ghost@example.test');
    }

    public function testRefreshReloadsByPersistedId(): void
    {
        $stale = new User('stale@example.test', 'hash', 'Stale');
        (new \ReflectionProperty(User::class, 'id'))->setValue($stale, 5);
        $fresh = new User('stale@example.test', 'hash2', 'Fresh');
        $this->users->expects(self::once())->method('find')->with(5)->willReturn($fresh);

        self::assertSame($fresh, $this->provider->refreshUser($stale));
    }

    public function testRefreshDeletedUserDropsSession(): void
    {
        $stale = new User('gone@example.test', 'hash', 'Gone');
        (new \ReflectionProperty(User::class, 'id'))->setValue($stale, 9);
        $this->users->method('find')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->provider->refreshUser($stale);
    }

    public function testSupportsUserClass(): void
    {
        self::assertTrue($this->provider->supportsClass(User::class));
        self::assertFalse($this->provider->supportsClass(\stdClass::class));
    }
}
