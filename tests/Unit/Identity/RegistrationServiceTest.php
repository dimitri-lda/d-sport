<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Application\Identity\AccountMailer;
use App\Application\Identity\RegisterInput;
use App\Application\Identity\RegistrationService;
use App\Application\Identity\VerificationLinkSigner;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\UserRepository;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(RegistrationService::class)]
#[CoversClass(AccountMailer::class)]
final class RegistrationServiceTest extends TestCase
{
    private UserRepository $users;
    private UserPasswordHasherInterface $hasher;
    private EntityManagerInterface $em;
    private MailerInterface $mailer;
    private LoggerInterface $logger;
    private RegistrationService $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        // Persistence assigns the id the verification signer requires.
        $this->em->method('persist')->willReturnCallback(static function (User $user): void {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, 1);
        });
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new RegistrationService(
            $this->users,
            $this->hasher,
            $this->em,
            new VerificationLinkSigner('https://shop.example.test', 'test-secret', $this->em),
            new AccountMailer($this->mailer, $this->logger, 'Sport E <no-reply@sport-e.example>'),
        );
        $this->now = new \DateTimeImmutable('2026-02-01T12:00:00+00:00');
    }

    private static function input(array $overrides = []): RegisterInput
    {
        return new RegisterInput(
            $overrides['display_name'] ?? 'New Customer',
            $overrides['email'] ?? 'new@example.test',
            $overrides['password'] ?? 'Sport-E!2026',
            $overrides['password_confirmation'] ?? 'Sport-E!2026',
            $overrides['accepted_terms'] ?? true,
        );
    }

    public function testRegisterNormalizesEmailPersistsAndAcceptsTerms(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('bcrypt-hash');
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        // Flushed once for the user row and once when the verification
        // signer mints the nonce.
        $this->em->expects(self::exactly(2))->method('flush');

        $user = $this->service->register(self::input(['email' => '  Mixed@Example.TEST  ']), $this->now);

        self::assertSame('mixed@example.test', $user->getEmail());
        self::assertSame('bcrypt-hash', $user->getPassword());
        self::assertTrue($user->hasAcceptedTerms());
        self::assertSame($this->now, $user->getTermsAcceptedAt());
    }

    public function testRegisterAttemptsVerificationMailAfterPersist(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('bcrypt-hash');
        $this->mailer->expects(self::once())->method('send');

        $user = $this->service->register(self::input(), $this->now);

        self::assertInstanceOf(User::class, $user);
    }

    public function testMailTransportFailureDoesNotBreakRegistration(): void
    {
        $this->users->method('findByEmail')->willReturn(null);
        $this->hasher->method('hashPassword')->willReturn('bcrypt-hash');
        $this->mailer->method('send')->willThrowException(new TransportException('smtp down'));
        $this->logger->expects(self::once())->method('error')->with(
            'Account mail delivery failed.',
            self::callback(self::safeMailLogContext(...)),
        );

        $user = $this->service->register(self::input(), $this->now);

        self::assertSame('new@example.test', $user->getEmail());
    }

    /** Log context must carry kind + exception only — never recipients/URLs. */
    private static function safeMailLogContext(array $context): bool
    {
        return 'verification' === ($context['mail'] ?? null)
            && TransportException::class === ($context['exception'] ?? null)
            && !str_contains((string) ($context['reason'] ?? ''), '@');
    }

    public function testDuplicateEmailIsMappedToEmailTaken422(): void
    {
        $this->users->method('findByEmail')->willReturn(new User('taken@example.test', 'h', 'Taken'));
        $this->em->expects(self::never())->method('persist');

        try {
            $this->service->register(self::input(['email' => 'taken@example.test']), $this->now);
            self::fail('Expected email_taken ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame('email_taken', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertArrayHasKey('email', $problem->fields);
        }
    }

    public function testValidationFailuresCarryFields(): void
    {
        $this->em->expects(self::never())->method('persist');

        try {
            $this->service->register(self::input([
                'email' => 'not-an-email',
                'password' => 'weak',
                'password_confirmation' => 'different',
                'accepted_terms' => false,
            ]), $this->now);
            self::fail('Expected validation_failed ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame('validation_failed', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertArrayHasKey('email', $problem->fields);
            self::assertArrayHasKey('password', $problem->fields);
            self::assertArrayHasKey('password_confirmation', $problem->fields);
            self::assertArrayHasKey('accepted_terms', $problem->fields);
        }
    }
}
