<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\DomainException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_images')]
#[ORM\UniqueConstraint(name: 'product_images_position_unique', columns: ['product_id', 'position'])]
class ProductImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'images')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Column(type: Types::TEXT)]
    private string $url;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, string $url, ?string $alt, int $position)
    {
        if ($position < 0) {
            throw new DomainException('Image position must be >= 0.');
        }
        $this->product = $product;
        $this->url = $url;
        $this->alt = $alt;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
