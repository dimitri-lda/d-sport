<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'category_translations')]
class CategoryTranslation
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Id]
    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(length: 160)]
    private string $name;

    public function __construct(Category $category, string $locale, string $name)
    {
        $this->category = $category;
        $this->locale = $locale;
        $this->name = $name;
    }

    public function getCategory(): Category
    {
        return $this->category;
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
