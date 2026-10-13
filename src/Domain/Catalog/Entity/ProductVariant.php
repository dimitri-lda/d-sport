<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\Catalog\Repository\ProductVariantRepository;
use App\Domain\DomainException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
#[ORM\Table(name: 'product_variants')]
#[ORM\UniqueConstraint(name: 'product_variants_sku_unique', columns: ['sku'])]
class ProductVariant
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'variants')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    /** @var Collection<string, ProductVariantTranslation> */
    #[ORM\OneToMany(targetEntity: ProductVariantTranslation::class, mappedBy: 'variant', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(name: 'price_cents_net', type: Types::INTEGER, nullable: true)]
    private ?int $priceCentsNet;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $stock;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Product $product, string $sku, ?int $priceCentsNet = null, int $stock = 0)
    {
        if (null !== $priceCentsNet && $priceCentsNet < 0) {
            throw new DomainException('Variant price must be >= 0.');
        }
        if ($stock < 0) {
            throw new DomainException('Variant stock must be >= 0.');
        }
        $this->product = $product;
        $this->sku = $sku;
        $this->priceCentsNet = $priceCentsNet;
        $this->stock = $stock;
        $this->translations = new ArrayCollection();
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

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getPriceCentsNet(): int
    {
        return $this->priceCentsNet ?? $this->product->getPriceCentsNet();
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function reserve(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException('Reserve quantity must be > 0.');
        }
        if ($this->stock < $quantity) {
            throw new DomainException(\sprintf('Insufficient stock for SKU %s.', $this->sku));
        }
        $this->stock -= $quantity;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function release(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException('Release quantity must be > 0.');
        }
        $this->stock += $quantity;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function addTranslation(string $locale, string $name): ProductVariantTranslation
    {
        $translation = new ProductVariantTranslation($this, $locale, $name);
        $this->translations->set($locale, $translation);

        return $translation;
    }

    public function getTranslation(string $locale): ?ProductVariantTranslation
    {
        return $this->translations->get($locale);
    }
}
