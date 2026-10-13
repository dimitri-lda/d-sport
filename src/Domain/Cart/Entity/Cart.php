<?php

declare(strict_types=1);

namespace App\Domain\Cart\Entity;

use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\DomainException;
use App\Domain\Identity\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CartRepository::class)]
#[ORM\Table(name: 'carts')]
#[ORM\UniqueConstraint(name: 'carts_user_unique', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'carts_guest_token_unique', columns: ['guest_token'])]
class Cart
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?User $user;

    #[ORM\Column(name: 'guest_token', type: Types::GUID, nullable: true)]
    private ?string $guestToken;

    /** @var Collection<int, CartItem> */
    #[ORM\OneToMany(targetEntity: CartItem::class, mappedBy: 'cart', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    private function __construct(?User $user, ?string $guestToken)
    {
        if ((null === $user) === (null === $guestToken)) {
            throw new DomainException('A cart must be either user-owned or guest-owned.');
        }
        $this->user = $user;
        $this->guestToken = $guestToken;
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public static function forUser(User $user): self
    {
        return new self($user, null);
    }

    public static function forGuest(string $guestToken): self
    {
        return new self(null, $guestToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getGuestToken(): ?string
    {
        return $this->guestToken;
    }

    /** @return Collection<int, CartItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    /**
     * Normal add semantics: the requested quantity is added to any existing
     * line and the resulting line is capped at min(stock, maxQuantity).
     */
    public function addItem(ProductVariant $variant, int $quantity, int $maxQuantity = 20): CartItem
    {
        if ($quantity <= 0) {
            throw new DomainException('Cart item quantity must be > 0.');
        }
        $cap = min($variant->getStock(), $maxQuantity);
        if ($cap <= 0) {
            throw new DomainException('Variant is out of stock.');
        }
        $existing = $this->findItemByVariant($variant);
        if (null !== $existing) {
            $existing->setQuantity(min($existing->getQuantity() + $quantity, $cap));
            $this->touch();

            return $existing;
        }
        $item = new CartItem($this, $variant, min($quantity, $cap));
        $this->items->add($item);
        $this->touch();

        return $item;
    }

    /**
     * Merge semantics: replace any existing line for the variant with the
     * requested quantity (still bounded by stock and the max cap).
     */
    public function addOrReplaceItem(ProductVariant $variant, int $quantity, int $maxQuantity = 20): CartItem
    {
        if ($quantity <= 0) {
            throw new DomainException('Cart item quantity must be > 0.');
        }
        $cap = min($variant->getStock(), $maxQuantity);
        if ($cap <= 0) {
            throw new DomainException('Variant is out of stock.');
        }
        $existing = $this->findItemByVariant($variant);
        if (null !== $existing) {
            $existing->setQuantity(min($quantity, $cap));
            $this->touch();

            return $existing;
        }

        return $this->addItem($variant, $quantity, $maxQuantity);
    }

    public function findItem(int $id): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getId() === $id) {
                return $item;
            }
        }

        return null;
    }

    public function removeItem(CartItem $item): void
    {
        $this->items->removeElement($item);
        $this->touch();
    }

    public function clear(): void
    {
        $this->items->clear();
        $this->touch();
    }

    private function findItemByVariant(ProductVariant $variant): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getVariant()->getId() === $variant->getId()) {
                return $item;
            }
        }

        return null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
