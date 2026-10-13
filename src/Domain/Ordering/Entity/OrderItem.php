<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\DomainException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'product_variant_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ProductVariant $variant;

    #[ORM\Column(length: 200)]
    private string $name;

    #[ORM\Column(name: 'variant_name', length: 200)]
    private string $variantName;

    #[ORM\Column(name: 'unit_price_cents', type: Types::INTEGER)]
    private int $unitPriceCents;

    #[ORM\Column(name: 'net_unit_price_cents', type: Types::INTEGER, nullable: true)]
    private ?int $netUnitPriceCents;

    #[ORM\Column(type: Types::INTEGER)]
    private int $quantity;

    #[ORM\Column(name: 'line_total_cents', type: Types::INTEGER)]
    private int $lineTotalCents;

    #[ORM\Column(name: 'tax_cents', type: Types::INTEGER, nullable: true)]
    private ?int $taxCents;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Order $order,
        ?ProductVariant $variant,
        string $name,
        string $variantName,
        int $unitPriceCents,
        int $quantity,
        ?int $netUnitPriceCents = null,
        ?int $taxCents = null,
    ) {
        if ($unitPriceCents < 0 || $quantity <= 0) {
            throw new DomainException('Order item price must be >= 0 and quantity > 0.');
        }
        if (null !== $netUnitPriceCents && $netUnitPriceCents < 0) {
            throw new DomainException('Order item net unit price must be >= 0.');
        }
        if (null !== $taxCents && $taxCents < 0) {
            throw new DomainException('Order item tax must be >= 0.');
        }
        $this->order = $order;
        $this->variant = $variant;
        $this->name = $name;
        $this->variantName = $variantName;
        $this->unitPriceCents = $unitPriceCents;
        $this->netUnitPriceCents = $netUnitPriceCents;
        $this->quantity = $quantity;
        $this->lineTotalCents = $unitPriceCents * $quantity;
        $this->taxCents = $taxCents;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVariant(): ?ProductVariant
    {
        return $this->variant;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVariantName(): string
    {
        return $this->variantName;
    }

    public function getUnitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getLineTotalCents(): int
    {
        return $this->lineTotalCents;
    }

    public function getNetUnitPriceCents(): ?int
    {
        return $this->netUnitPriceCents;
    }

    public function getTaxCents(): ?int
    {
        return $this->taxCents;
    }
}
