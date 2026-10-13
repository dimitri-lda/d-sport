<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\DomainException;
use App\Domain\Identity\Repository\UserRepository;
use App\Domain\Identity\UserRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'users_remember_token_unique', columns: ['remember_token'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** @var non-empty-string */
    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'display_name', length: 120)]
    private string $displayName;

    #[ORM\Column(enumType: UserRole::class, length: 20, options: ['default' => 'user'])]
    private UserRole $role;

    #[ORM\Column(name: 'email_verified_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(name: 'remember_token', length: 100, nullable: true)]
    private ?string $rememberToken = null;

    #[ORM\Column(name: 'accepted_terms', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $acceptedTerms = false;

    #[ORM\Column(name: 'terms_accepted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /** @param list<string> $roles */
    public function __construct(string $email, string $passwordHash, string $displayName, array $roles = ['ROLE_USER'])
    {
        if ('' === $email) {
            throw new DomainException('User email must not be empty.');
        }
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->displayName = $displayName;
        $this->role = UserRole::fromSecurityRoles($roles);
        $this->createdAt = $this->now();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->toSecurityRole()];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Session-refresh comparison used by the context listener: once this
     * returns false — identifier, role or password hash changed — the
     * stored security token is discarded, so a password change kills every
     * other session for the account.
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if ($this->getUserIdentifier() !== $user->getUserIdentifier()) {
            return false;
        }
        if ($this->getRoles() !== $user->getRoles()) {
            return false;
        }
        if ($user instanceof PasswordAuthenticatedUserInterface && $this->getPassword() !== $user->getPassword()) {
            return false;
        }

        return true;
    }

    public function isEmailVerified(): bool
    {
        return null !== $this->emailVerifiedAt;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function verifyEmail(\DateTimeImmutable $at): void
    {
        $this->emailVerifiedAt ??= $at;
        $this->updatedAt = $this->now();
    }

    public function unverifyEmail(): void
    {
        $this->emailVerifiedAt = null;
        $this->updatedAt = $this->now();
    }

    public function changeProfile(string $displayName, string $email): void
    {
        if ('' === $email) {
            throw new DomainException('User email must not be empty.');
        }
        $this->displayName = $displayName;
        $this->email = $email;
        $this->updatedAt = $this->now();
    }

    public function changePassword(string $hash): void
    {
        $this->passwordHash = $hash;
        // Outstanding remember-me material must die with the old password.
        $this->rememberToken = null;
        $this->updatedAt = $this->now();
    }

    public function acceptTerms(\DateTimeImmutable $at): void
    {
        $this->acceptedTerms = true;
        $this->termsAcceptedAt = $at;
        $this->updatedAt = $this->now();
    }

    public function hasAcceptedTerms(): bool
    {
        return $this->acceptedTerms;
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
