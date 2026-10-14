<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Application\Commerce\CountryCatalog;
use App\Application\Identity\AccountMailer;
use App\Application\Identity\AddressInput;
use App\Application\Identity\ProfileService;
use App\Application\Identity\VerificationLinkSigner;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\SavedAddressRepository;
use App\Domain\Identity\Repository\UserRepository;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(ProfileService::class)]
final class ProfileServiceTest extends TestCase
{
    private UserRepository $users;
    private SavedAddressRepository $addresses;
    private MailerInterface $mailer;
    private EntityManagerInterface $em;
    private ProfileService $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->addresses = $this->createMock(SavedAddressRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(true);
        $hasher->method('hashPassword')->willReturn('new-hash');
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new ProfileService(
            $this->users,
            $this->addresses,
            $hasher,
            new AccountMailer($this->mailer, new NullLogger(), 'Sport E <no-reply@sport-e.example>'),
            new VerificationLinkSigner('https://shop.example.test', 'test-secret', $this->em),
            $this->em,
            new CountryCatalog(
                ['PL' => ['code' => 'pl_pl', 'currency' => 'PLN', 'locale' => 'pl', 'vat_basis_points' => 2300]],
                ['DE' => 1900],
                ['PL' => ['en' => 'Poland'], 'DE' => ['en' => 'Germany']],
                ['en'],
                'PL',
                'eu_global',
            ),
        );
        $this->now = new \DateTimeImmutable('2026-02-01T12:00:00+00:00');
    }

    private static function verifiedUser(int $id = 5): User
    {
        $user = new User('customer@example.test', 'hash', 'Customer');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->verifyEmail(new \DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        return $user;
    }

    public function testEmailChangeResetsVerificationAndMailsFreshLink(): void
    {
        $user = self::verifiedUser();
        $this->users->method('findByEmail')->willReturn(null);
        $this->em->expects(self::once())->method('flush');
        $this->mailer->expects(self::once())->method('send');

        $this->service->updateProfile($user, 'Customer', 'NEW@example.test', $this->now);

        self::assertSame('new@example.test', $user->getEmail());
        self::assertFalse($user->isEmailVerified());
    }

    public function testUnchangedEmailKeepsVerificationAndSendsNoMail(): void
    {
        $user = self::verifiedUser();
        $this->users->expects(self::never())->method('findByEmail');
        $this->mailer->expects(self::never())->method('send');

        $this->service->updateProfile($user, '  Renamed  ', 'customer@example.test', $this->now);

        self::assertSame('Renamed', $user->getDisplayName());
        self::assertTrue($user->isEmailVerified());
    }

    public function testTakenEmailIsRejected(): void
    {
        $user = self::verifiedUser();
        $other = self::verifiedUser(9);
        $this->users->method('findByEmail')->with('other@example.test')->willReturn($other);

        try {
            $this->service->updateProfile($user, 'Customer', 'other@example.test', $this->now);
            self::fail('Expected email_taken ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame('email_taken', $problem->errorCode);
        }
    }

    public function testAddressRejectsUnsupportedCountry(): void
    {
        $user = self::verifiedUser();
        $this->addresses->expects(self::never())->method('findForUser');

        try {
            $this->service->updateAddress($user, new AddressInput(
                'Jane', 'Doe', 'Main St 1', null, 'Berlin', '10115', 'US',
            ));
            self::fail('Expected validation_failed ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame('validation_failed', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertArrayHasKey('country', $problem->fields);
        }
    }

    public function testAddressNormalizesLowercaseSupportedCountry(): void
    {
        $user = self::verifiedUser();
        $this->addresses->method('findForUser')->willReturn(null);
        $this->em->expects(self::once())->method('persist');

        $address = $this->service->updateAddress($user, new AddressInput(
            'Jane', 'Doe', 'Main St 1', null, 'Berlin', '10115', 'de',
        ));

        self::assertSame('DE', $address->getCountry());
    }
}
