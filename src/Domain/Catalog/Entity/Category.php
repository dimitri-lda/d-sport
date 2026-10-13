<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Entity;

use App\Domain\DomainException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
#[ORM\Index(name: 'categories_parent_idx', columns: ['parent_id'])]
#[ORM\UniqueConstraint(name: 'categories_slug_unique', columns: ['slug'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Category $parent;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(targetEntity: Category::class, mappedBy: 'parent')]
    private Collection $children;

    /** @var Collection<string, CategoryTranslation> */
    #[ORM\OneToMany(targetEntity: CategoryTranslation::class, mappedBy: 'category', cascade: ['persist'], orphanRemoval: true, indexBy: 'locale')]
    private Collection $translations;

    #[ORM\Column(length: 160)]
    private string $slug;

    #[ORM\Column(name: 'image_url', type: Types::TEXT, nullable: true)]
    private ?string $imageUrl;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $position;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, ?Category $parent = null, int $position = 0, ?string $imageUrl = null)
    {
        if ($position < 0) {
            throw new DomainException('Category position must be >= 0.');
        }
        $this->slug = $slug;
        $this->parent = $parent;
        $this->position = $position;
        $this->imageUrl = $imageUrl;
        $this->children = new ArrayCollection();
        $this->translations = new ArrayCollection();
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

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function addTranslation(string $locale, string $name): CategoryTranslation
    {
        $translation = new CategoryTranslation($this, $locale, $name);
        $this->translations->set($locale, $translation);

        return $translation;
    }

    public function getTranslation(string $locale): ?CategoryTranslation
    {
        return $this->translations->get($locale);
    }

    /** @return Collection<string, CategoryTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }
}
