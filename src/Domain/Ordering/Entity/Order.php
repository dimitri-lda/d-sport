<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Entity;

use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\DomainException;
use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\OrderStatus;
use App\Domain\Ordering\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
#[ORM\Index(name: 'orders_status_idx', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'orders_user_history_idx', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'orders_created_idx', columns: ['created_at', 'id'])]
#[ORM\UniqueConstraint(name: 'orders_number_unique', columns: ['number'])]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 20)]
    private string $number;

    #[ORM\Column(length: 320)]
    private string $email;

    #[ORM\Column(length: 40)]
    private string $phone;

    #[ORM\Column(enumType: OrderStatus::class, length: 20, options: ['default' => 'pending_payment'])]
    private OrderStatus $status = OrderStatus::PendingPayment;

    #[ORM\Column(name: 'delivery_method', enumType: DeliveryMethod::class, length: 20)]
    private DeliveryMethod $deliveryMethod;

    #[ORM\Column(name: 'subtotal_cents', type: Types::INTEGER)]
    private int $subtotalCents;

    #[ORM\Column(name: 'delivery_cents', type: Types::INTEGER)]
    private int $deliveryCents;

    #[ORM\Column(name: 'total_cents', type: Types::INTEGER)]
    private int $totalCents;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(length: 20)]
    private string $market;

    #[ORM\Column(length: 5)]
    private string $locale;

    #[ORM\Column(name: 'base_currency', length: 3, options: ['fixed' => true, 'default' => 'EUR'])]
    private string $baseCurrency;

    #[ORM\Column(name: 'exchange_rate', type: Types::DECIMAL, precision: 18, scale: 8, nullable: true)]
    private ?string $exchangeRate;

    #[ORM\Column(name: 'vat_rate_basis_points', type: Types::SMALLINT, nullable: true)]
    private ?int $vatRateBasisPoints;

    #[ORM\Column(name: 'net_subtotal_cents', type: Types::INTEGER, nullable: true)]
    private ?int $netSubtotalCents;

    #[ORM\Column(name: 'tax_cents', type: Types::INTEGER, nullable: true)]
    private ?int $taxCents;

    #[ORM\Column(name: 'delivery_net_cents', type: Types::INTEGER, nullable: true)]
    private ?int $deliveryNetCents;

    #[ORM\Column(name: 'delivery_tax_cents', type: Types::INTEGER, nullable: true)]
    private ?int $deliveryTaxCents;

    #[ORM\Column(name: 'inventory_reserved_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inventoryReservedAt = null;

    #[ORM\Column(name: 'inventory_released_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $inventoryReleasedAt = null;

    /** @var Collection<int, OrderItem> */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\OneToOne(targetEntity: OrderAddress::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private ?OrderAddress $address = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $number,
        string $email,
        string $phone,
        DeliveryMethod $deliveryMethod,
        int $subtotalCents,
        int $deliveryCents,
        string $currency,
        string $market,
        string $locale,
        ?User $user = null,
        string $baseCurrency = 'EUR',
        ?string $exchangeRate = null,
        ?int $vatRateBasisPoints = null,
    ) {
        if ($subtotalCents < 0 || $deliveryCents < 0) {
            throw new DomainException('Order amounts must be >= 0.');
        }
        $this->number = $number;
        $this->email = $email;
        $this->phone = $phone;
        $this->deliveryMethod = $deliveryMethod;
        $this->subtotalCents = $subtotalCents;
        $this->deliveryCents = $deliveryCents;
        $this->totalCents = $subtotalCents + $deliveryCents;
        $this->currency = $currency;
        $this->market = $market;
        $this->locale = $locale;
        $this->user = $user;
        $this->baseCurrency = $baseCurrency;
        $this->exchangeRate = $exchangeRate;
        $this->vatRateBasisPoints = $vatRateBasisPoints;
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getDeliveryMethod(): DeliveryMethod
    {
        return $this->deliveryMethod;
    }

    public function getSubtotalCents(): int
    {
        return $this->subtotalCents;
    }

    public function getDeliveryCents(): int
    {
        return $this->deliveryCents;
    }

    public function getTotalCents(): int
    {
        return $this->totalCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getMarket(): string
    {
        return $this->market;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function getVatRateBasisPoints(): ?int
    {
        return $this->vatRateBasisPoints;
    }

    public function getNetSubtotalCents(): ?int
    {
        return $this->netSubtotalCents;
    }

    public function getTaxCents(): ?int
    {
        return $this->taxCents;
    }

    public function getDeliveryNetCents(): ?int
    {
        return $this->deliveryNetCents;
    }

    public function getDeliveryTaxCents(): ?int
    {
        return $this->deliveryTaxCents;
    }

    public function getInventoryReservedAt(): ?\DateTimeImmutable
    {
        return $this->inventoryReservedAt;
    }

    public function getInventoryReleasedAt(): ?\DateTimeImmutable
    {
        return $this->inventoryReleasedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setTaxBreakdown(int $netSubtotalCents, int $taxCents, int $deliveryNetCents, int $deliveryTaxCents): void
    {
        if ($netSubtotalCents < 0 || $taxCents < 0 || $deliveryNetCents < 0 || $deliveryTaxCents < 0) {
            throw new DomainException('Tax breakdown amounts must be >= 0.');
        }
        $this->netSubtotalCents = $netSubtotalCents;
        $this->taxCents = $taxCents;
        $this->deliveryNetCents = $deliveryNetCents;
        $this->deliveryTaxCents = $deliveryTaxCents;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function transitionTo(OrderStatus $next, \DateTimeImmutable $at): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new DomainException(\sprintf('Order %s cannot transition from %s to %s.', $this->number, $this->status->value, $next->value));
        }
        $this->status = $next;
        $this->updatedAt = $at;
    }

    public function reserveInventory(\DateTimeImmutable $at): void
    {
        if (null !== $this->inventoryReservedAt) {
            throw new DomainException(\sprintf('Inventory for order %s is already reserved.', $this->number));
        }
        $this->inventoryReservedAt = $at;
        $this->updatedAt = $at;
    }

    /** Idempotent: a second release is a no-op. */
    public function releaseInventory(\DateTimeImmutable $at): void
    {
        if (null !== $this->inventoryReleasedAt) {
            return;
        }
        if (null === $this->inventoryReservedAt) {
            throw new DomainException(\sprintf('Cannot release inventory for order %s before it is reserved.', $this->number));
        }
        if ($at < $this->inventoryReservedAt) {
            throw new DomainException(\sprintf('Inventory release for order %s cannot precede its reservation.', $this->number));
        }
        $this->inventoryReleasedAt = $at;
        $this->updatedAt = $at;
    }

    public function isInventoryReleased(): bool
    {
        return null !== $this->inventoryReleasedAt;
    }

    public function addItem(
        ProductVariant $variant,
        string $name,
        string $variantName,
        int $unitPriceCents,
        int $quantity,
        ?int $netUnitPriceCents = null,
        ?int $taxCents = null,
    ): OrderItem {
        $item = new OrderItem($this, $variant, $name, $variantName, $unitPriceCents, $quantity, $netUnitPriceCents, $taxCents);
        $this->items->add($item);

        return $item;
    }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function setAddress(OrderAddress $address): void
    {
        $this->address = $address;
    }

    public function getAddress(): ?OrderAddress
    {
        return $this->address;
    }
}
