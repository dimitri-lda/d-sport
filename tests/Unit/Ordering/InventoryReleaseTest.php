<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Application\Catalog\CatalogCacheInvalidator;
use App\Application\Ordering\InventoryRelease;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\Entity\Order;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryRelease::class)]
final class InventoryReleaseTest extends TestCase
{
    private EntityManagerInterface $em;
    private CatalogCacheInvalidator $invalidator;
    private InventoryRelease $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(
            fn (callable $callback): mixed => $callback($this->em),
        );
        $this->invalidator = $this->createMock(CatalogCacheInvalidator::class);
        $this->service = new InventoryRelease($this->em, $this->invalidator);
    }

    private static function variant(int $stock): ProductVariant
    {
        return new ProductVariant(new Product(new Category('cat'), 'product', 1000), 'SKU-1', null, $stock);
    }

    private static function order(ProductVariant $variant): Order
    {
        $order = new Order('SP-1234ABCD', 'b@example.test', '+123', DeliveryMethod::Standard, 1190, 0, 'EUR', 'eu_global', 'en');
        $order->addItem($variant, 'Product', 'Variant', 1190, 2, 1000, 380);
        $order->reserveInventory(new \DateTimeImmutable('2026-02-01T12:00:00+00:00'));
        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, 42);

        return $order;
    }

    public function testReleaseLocksOrderRowThenRestoresLockedVariantStock(): void
    {
        $variant = self::variant(3);
        $order = self::order($variant);
        $this->em->expects(self::once())->method('find')
            ->with(Order::class, 42)
            ->willReturn($order);
        $locked = [];
        $this->em->expects(self::exactly(2))->method('lock')
            ->willReturnCallback(static function (object $entity, LockMode $mode) use (&$locked): void {
                $locked[] = [$entity, $mode];
            });
        $this->em->expects(self::once())->method('refresh')->with($order);
        $this->em->expects(self::once())->method('flush');
        $at = new \DateTimeImmutable('2026-02-01T13:00:00+00:00');

        $this->service->release($order, $at);

        // Order row first, variant second — decisions never read stale state.
        self::assertSame(
            [[$order, LockMode::PESSIMISTIC_WRITE], [$variant, LockMode::PESSIMISTIC_WRITE]],
            $locked,
        );
        self::assertSame(5, $variant->getStock());
        self::assertTrue($order->isInventoryReleased());
        self::assertSame($at, $order->getInventoryReleasedAt());
    }

    public function testSecondReleaseIsANoOp(): void
    {
        $variant = self::variant(3);
        $order = self::order($variant);
        $variantLocks = 0;
        $this->em->method('find')->willReturn($order);
        $this->em->method('lock')->willReturnCallback(
            static function (object $entity) use (&$variantLocks, $variant): void {
                if ($entity === $variant) {
                    ++$variantLocks;
                }
            },
        );

        $this->service->release($order, new \DateTimeImmutable('2026-02-01T13:00:00+00:00'));
        $this->service->release($order, new \DateTimeImmutable('2026-02-01T14:00:00+00:00'));

        self::assertSame(1, $variantLocks, 'Idempotent release must not restore stock twice.');
        self::assertSame(5, $variant->getStock());
    }

    public function testStaleInstanceDecidedByFreshRowState(): void
    {
        $variant = self::variant(3);
        $stale = self::order($variant);
        $fresh = self::order($variant);
        $fresh->releaseInventory(new \DateTimeImmutable('2026-02-01T12:30:00+00:00'));
        $variantLocks = 0;
        $this->em->method('find')->willReturn($fresh);
        $this->em->method('lock')->willReturnCallback(
            static function (object $entity) use (&$variantLocks, $variant): void {
                if ($entity === $variant) {
                    ++$variantLocks;
                }
            },
        );

        $this->service->release($stale, new \DateTimeImmutable('2026-02-01T13:00:00+00:00'));

        self::assertSame(0, $variantLocks, 'A released row must veto a stale unreleased instance.');
        self::assertSame(3, $variant->getStock());
    }

    public function testCommittedReleaseInvalidatesTheCatalogCacheOnce(): void
    {
        $variant = self::variant(3);
        $order = self::order($variant);
        $this->em->method('find')->willReturn($order);
        $this->invalidator->expects(self::once())->method('productsChanged');

        $this->service->release($order, new \DateTimeImmutable('2026-02-01T13:00:00+00:00'));
    }

    public function testRolledBackReleaseDoesNotInvalidate(): void
    {
        $variant = self::variant(3);
        $order = self::order($variant);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willThrowException(new \RuntimeException('connection lost'));
        $service = new InventoryRelease($em, $this->invalidator);
        $this->invalidator->expects(self::never())->method('productsChanged');

        try {
            $service->release($order, new \DateTimeImmutable('2026-02-01T13:00:00+00:00'));
            self::fail('The transaction failure must propagate.');
        } catch (\RuntimeException) {
        }
    }
}
