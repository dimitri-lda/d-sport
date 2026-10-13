<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\DomainException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_specifications')]
class ProductSpecification
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'specifications')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $value;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $unit;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, string $name, string $value, ?string $unit, int $position)
    {
        if ($position < 0) {
            throw new DomainException('Specification position must be >= 0.');
        }
        $this->product = $product;
        $this->name = $name;
        $this->value = $value;
        $this->unit = $unit;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
