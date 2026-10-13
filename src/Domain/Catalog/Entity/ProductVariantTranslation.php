<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_variant_translations')]
class ProductVariantTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ProductVariant::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\Id]
    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 160)]
    private string $name;

    public function __construct(ProductVariant $variant, string $locale, string $name)
    {
        $this->variant = $variant;
        $this->locale = $locale;
        $this->name = $name;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
