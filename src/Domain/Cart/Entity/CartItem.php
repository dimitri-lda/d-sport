<?php

declare(strict_types=1);

namespace App\Domain\Cart\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\DomainException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cart_items')]
#[ORM\Index(name: 'cart_items_cart_idx', columns: ['cart_id'])]
#[ORM\UniqueConstraint(name: 'cart_items_variant_unique', columns: ['cart_id', 'product_variant_id'])]
class CartItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Cart::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'cart_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Cart $cart;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'product_variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ProductVariant $variant;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Cart $cart, ProductVariant $variant, int $quantity)
    {
        if ($quantity <= 0) {
            throw new DomainException('Cart item quantity must be > 0.');
        }
        $this->cart = $cart;
        $this->variant = $variant;
        $this->quantity = $quantity;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw new DomainException('Cart item quantity must be > 0.');
        }
        $this->quantity = $quantity;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
