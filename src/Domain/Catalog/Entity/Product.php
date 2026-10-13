<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\DomainException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
#[ORM\Index(name: 'products_featured_idx', columns: ['id'], options: ['where' => '(active AND featured)'])]
#[ORM\Index(name: 'products_category_price_idx', columns: ['category_id', 'price_cents_net', 'id'], options: ['where' => 'active'])]
#[ORM\Index(name: 'products_price_idx', columns: ['price_cents_net', 'id'], options: ['where' => 'active'])]
#[ORM\Index(name: 'products_active_id_idx', columns: ['id'], options: ['where' => 'active'])]
#[ORM\UniqueConstraint(name: 'products_slug_unique', columns: ['slug'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Category $category;

    #[ORM\ManyToOne(targetEntity: Brand::class)]
    #[ORM\JoinColumn(name: 'brand_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Brand $brand;

    #[ORM\ManyToOne(targetEntity: Manufacturer::class)]
    #[ORM\JoinColumn(name: 'manufacturer_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Manufacturer $manufacturer;

    /** @var Collection<string, ProductTranslation> */
    #[ORM\OneToMany(targetEntity: ProductTranslation::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    /** @var Collection<int, ProductVariant> */
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    private Collection $variants;

    /** @var Collection<int, ProductImage> */
    #[ORM\OneToMany(targetEntity: ProductImage::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    private Collection $images;

    /** @var Collection<int, ProductSpecification> */
    #[ORM\OneToMany(targetEntity: ProductSpecification::class, mappedBy: 'product', cascade: ['persist'], orphanRemoval: true)]
    private Collection $specifications;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(name: 'price_cents_net', type: Types::INTEGER)]
    private int $priceCentsNet;

    #[ORM\Column(name: 'compare_at_price_cents_net', type: Types::INTEGER, nullable: true)]
    private ?int $compareAtPriceCentsNet;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $featured;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Category $category,
        string $slug,
        int $priceCentsNet,
        ?Brand $brand = null,
        ?Manufacturer $manufacturer = null,
        ?int $compareAtPriceCentsNet = null,
        bool $featured = false,
        bool $active = true,
    ) {
        if ($priceCentsNet < 0) {
            throw new DomainException('Product price must be >= 0.');
        }
        if (null !== $compareAtPriceCentsNet && $compareAtPriceCentsNet < $priceCentsNet) {
            throw new DomainException('Compare-at price must be null or >= product price.');
        }
        $this->category = $category;
        $this->slug = $slug;
        $this->priceCentsNet = $priceCentsNet;
        $this->brand = $brand;
        $this->manufacturer = $manufacturer;
        $this->compareAtPriceCentsNet = $compareAtPriceCentsNet;
        $this->featured = $featured;
        $this->active = $active;
        $this->translations = new ArrayCollection();
        $this->variants = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->specifications = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function getBrand(): ?Brand
    {
        return $this->brand;
    }

    public function getManufacturer(): ?Manufacturer
    {
        return $this->manufacturer;
    }

    public function getPriceCentsNet(): int
    {
        return $this->priceCentsNet;
    }

    public function getCompareAtPriceCentsNet(): ?int
    {
        return $this->compareAtPriceCentsNet;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function addTranslation(string $locale, string $name, string $description): ProductTranslation
    {
        $translation = new ProductTranslation($this, $locale, $name, $description);
        $this->translations->set($locale, $translation);

        return $translation;
    }

    public function getTranslation(string $locale): ?ProductTranslation
    {
        return $this->translations->get($locale);
    }

    public function addVariant(string $sku, ?int $priceCentsNet = null, int $stock = 0): ProductVariant
    {
        $variant = new ProductVariant($this, $sku, $priceCentsNet, $stock);
        $this->variants->add($variant);

        return $variant;
    }

    /** @return Collection<int, ProductVariant> */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addImage(string $url, ?string $alt, int $position): ProductImage
    {
        $image = new ProductImage($this, $url, $alt, $position);
        $this->images->add($image);

        return $image;
    }

    /** @return Collection<int, ProductImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addSpecification(string $name, string $value, ?string $unit, int $position): ProductSpecification
    {
        $specification = new ProductSpecification($this, $name, $value, $unit, $position);
        $this->specifications->add($specification);

        return $specification;
    }

    /** @return Collection<int, ProductSpecification> */
    public function getSpecifications(): Collection
    {
        return $this->specifications;
    }
}
