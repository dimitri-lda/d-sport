<?php

declare(strict_types=1);

namespace App\Domain\Identity\Entity;

use App\Domain\DomainException;
use App\Domain\Identity\Repository\SavedAddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedAddressRepository::class)]
#[ORM\Table(name: 'saved_addresses')]
#[ORM\UniqueConstraint(name: 'saved_addresses_user_unique', columns: ['user_id'])]
class SavedAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'first_name', length: 80)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 80)]
    private string $lastName;

    #[ORM\Column(length: 180)]
    private string $line1;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $line2;

    #[ORM\Column(length: 80)]
    private string $city;

    #[ORM\Column(name: 'postal_code', length: 20)]
    private string $postalCode;

    #[ORM\Column(length: 2, options: ['fixed' => true])]
    private string $country;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        string $firstName,
        string $lastName,
        string $line1,
        ?string $line2,
        string $city,
        string $postalCode,
        string $country,
    ) {
        $this->user = $user;
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = self::normalizeCountry($country);
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function update(
        string $firstName,
        string $lastName,
        string $line1,
        ?string $line2,
        string $city,
        string $postalCode,
        string $country,
    ): void {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->line1 = $line1;
        $this->line2 = $line2;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = self::normalizeCountry($country);
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getLine1(): string
    {
        return $this->line1;
    }

    public function getLine2(): ?string
    {
        return $this->line2;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    private static function normalizeCountry(string $country): string
    {
        $code = strtoupper(trim($country));
        if (1 !== preg_match('/^[A-Z]{2}$/', $code)) {
            throw new DomainException('Country must be a two-letter ISO code.');
        }

        return $code;
    }
}
