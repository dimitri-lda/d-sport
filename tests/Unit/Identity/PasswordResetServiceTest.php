<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Application\Identity\AccountMailer;
use App\Application\Identity\PasswordResetService;
use App\Application\Identity\ResetPasswordInput;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\PasswordResetTokenRepository;
use App\Domain\Identity\Repository\UserRepository;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(PasswordResetService::class)]
final class PasswordResetServiceTest extends TestCase
{
    private UserRepository $users;
    private PasswordResetTokenRepository $tokens;
    private UserPasswordHasherInterface $hasher;
    private MailerInterface $mailer;
    private EntityManagerInterface $em;
    private PasswordResetService $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->tokens = $this->createMock(PasswordResetTokenRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new PasswordResetService(
            $this->users,
            $this->tokens,
            $this->hasher,
            new AccountMailer($this->mailer, new NullLogger(), 'Sport E <no-reply@sport-e.example>'),
            $this->em,
            'https://shop.example.test',
        );
        $this->now = new \DateTimeImmutable('2026-02-01T12:00:00+00:00');
    }

    public function testForgotUnknownEmailDoesNothing(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->tokens->expects(self::never())->method('replaceFor');
        $this->mailer->expects(self::never())->method('send');

        $this->service->requestReset('ghost@example.test', $this->now);
    }

    public function testForgotKnownEmailStoresOnlyHashedToken(): void
    {
        $user = new User('customer@example.test', 'hash', 'Customer');
        $this->users->method('findByEmail')->with('customer@example.test')->willReturn($user);

        $storedHash = null;
        $this->tokens->expects(self::once())
            ->method('replaceFor')
            ->willReturnCallback(function (User $u, string $hash, \DateTimeImmutable $expires) use (&$storedHash): PasswordResetToken {
                $storedHash = $hash;

                return new PasswordResetToken($u, $hash, $expires);
            });
        $this->mailer->expects(self::once())->method('send');

        $this->service->requestReset('  Customer@Example.TEST ', $this->now);

        self::assertNotNull($storedHash);
        // SHA-256 hex of the raw token — never the raw token itself.
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $storedHash);
    }

    public function testForgotSurvivesTransportFailureForKnownEmail(): void
    {
        $user = new User('customer@example.test', 'hash', 'Customer');
        $this->users->method('findByEmail')->willReturn($user);
        $this->tokens->method('replaceFor')->willReturnCallback(
            static fn (User $u, string $hash, \DateTimeImmutable $expires): PasswordResetToken => new PasswordResetToken($u, $hash, $expires),
        );
        $this->mailer->method('send')->willThrowException(new TransportException('smtp down'));

        // Must not throw: the HTTP response stays the generic 202 either way.
        $this->service->requestReset('customer@example.test', $this->now);
        $this->addToAssertionCount(1);
    }

    public function testResetWithInvalidTokenRaisesFieldError(): void
    {
        $this->tokens->method('findValid')->willReturn(null);

        try {
            $this->service->reset(new ResetPasswordInput(
                'customer@example.test',
                'dead-token',
                'Sport-E!2026',
                'Sport-E!2026',
            ), $this->now);
            self::fail('Expected password_reset_invalid ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame('password_reset_invalid', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertArrayHasKey('token', $problem->fields);
        }
    }

    public function testResetUpdatesPasswordAndRemovesToken(): void
    {
        $user = new User('customer@example.test', 'old-hash', 'Customer');
        $token = new PasswordResetToken($user, hash('sha256', 'raw-token'), $this->now->add(new \DateInterval('PT30M')));
        $this->tokens->method('findValid')
            ->with('customer@example.test', hash('sha256', 'raw-token'), $this->now)
            ->willReturn($token);
        $this->hasher->method('hashPassword')->willReturn('new-hash');
        $this->em->expects(self::once())->method('remove')->with($token);
        $this->em->expects(self::once())->method('flush');

        $this->service->reset(new ResetPasswordInput(
            'customer@example.test',
            'raw-token',
            'Sport-E!2026',
            'Sport-E!2026',
        ), $this->now);

        self::assertSame('new-hash', $user->getPassword());
    }
}
