<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'product_translations')]
class ProductTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\Id]
    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    public function __construct(Product $product, string $locale, string $name, string $description)
    {
        $this->product = $product;
        $this->locale = $locale;
        $this->name = $name;
        $this->description = $description;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
