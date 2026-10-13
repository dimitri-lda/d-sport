<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Cart\Entity\Cart;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\DomainException;
use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Ordering\OrderStatus;
use PHPUnit\Framework\TestCase;

final class EntityInvariantTest extends TestCase
{
    public function testUserMapsScalarRoleToSecurityRole(): void
    {
        $user = new User('a@example.test', 'hash', 'A');
        $admin = new User('b@example.test', 'hash', 'B', ['ROLE_ADMIN', 'ROLE_USER']);
        $super = new User('c@example.test', 'hash', 'C', ['ROLE_SUPER_ADMIN']);

        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame(['ROLE_ADMIN'], $admin->getRoles());
        self::assertSame(['ROLE_SUPER_ADMIN'], $super->getRoles());
        self::assertSame('a@example.test', $user->getUserIdentifier());
        self::assertSame('hash', $user->getPassword());
    }

    public function testVerifyEmailIsIdempotentAndKeepsFirstTimestamp(): void
    {
        $user = new User('a@example.test', 'hash', 'A');
        self::assertFalse($user->isEmailVerified());

        $first = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $later = new \DateTimeImmutable('2026-02-01T00:00:00+00:00');
        $user->verifyEmail($first);
        $user->verifyEmail($later);

        self::assertTrue($user->isEmailVerified());
        self::assertSame($first, $user->getEmailVerifiedAt());
    }

    public function testCartOwnershipIsExclusiveByConstruction(): void
    {
        $userCart = Cart::forUser(new User('a@example.test', 'hash', 'A'));
        $guestCart = Cart::forGuest('00000000-0000-4000-8000-00000000000a');

        self::assertNull($userCart->getGuestToken());
        self::assertNotNull($userCart->getUser());
        self::assertNull($guestCart->getUser());
        self::assertSame('00000000-0000-4000-8000-00000000000a', $guestCart->getGuestToken());
    }

    public function testCartAddOrReplaceOverwritesQuantityInsteadOfSumming(): void
    {
        $variant = $this->makeVariant();
        $cart = Cart::forUser(new User('a@example.test', 'hash', 'A'));

        $cart->addOrReplaceItem($variant, 1);
        $cart->addOrReplaceItem($variant, 5);

        self::assertCount(1, $cart->getItems());
        self::assertSame(5, $cart->getItems()->first()->getQuantity());
    }

    public function testCartItemRejectsNonPositiveQuantity(): void
    {
        $this->expectException(DomainException::class);
        Cart::forGuest('00000000-0000-4000-8000-00000000000a')->addOrReplaceItem($this->makeVariant(), 0);
    }

    public function testVariantReserveDecrementsAndRejectsOversell(): void
    {
        $variant = $this->makeVariant(10);
        $variant->reserve(4);
        self::assertSame(6, $variant->getStock());

        $this->expectException(DomainException::class);
        $variant->reserve(7);
    }

    public function testVariantReleaseRestoresStock(): void
    {
        $variant = $this->makeVariant(10);
        $variant->reserve(4);
        $variant->release(4);

        self::assertSame(10, $variant->getStock());
    }

    public function testVariantPriceFallsBackToProductPrice(): void
    {
        $product = new Product(new Category('shoes'), 'runner', 15000);
        $inherited = $product->addVariant('SKU-INH', null, 1);
        $overridden = $product->addVariant('SKU-OVR', 12000, 1);

        self::assertSame(15000, $inherited->getPriceCentsNet());
        self::assertSame(12000, $overridden->getPriceCentsNet());
    }

    public function testCompareAtPriceCannotUndercutProductPrice(): void
    {
        $this->expectException(DomainException::class);
        new Product(new Category('shoes'), 'runner', 15000, null, null, 10000);
    }

    public function testOrderTotalIsComputedFromSubtotalAndDelivery(): void
    {
        $order = $this->makeOrder();
        self::assertSame(15590, $order->getTotalCents());
        self::assertSame(OrderStatus::PendingPayment, $order->getStatus());
    }

    public function testOrderTransitionToIllegalStatusThrows(): void
    {
        $order = $this->makeOrder();

        $this->expectException(DomainException::class);
        $order->transitionTo(OrderStatus::Shipped, new \DateTimeImmutable());
    }

    public function testOrderReleaseInventoryIsIdempotentAndOrdered(): void
    {
        $order = $this->makeOrder();
        $order->transitionTo(OrderStatus::Paid, new \DateTimeImmutable('2026-01-01T10:00:00+00:00'));

        $this->expectException(DomainException::class);
        $order->releaseInventory(new \DateTimeImmutable('2026-01-01T11:00:00+00:00'));
    }

    public function testOrderReleaseInventorySecondCallIsNoOp(): void
    {
        $order = $this->makeOrder();
        $reserved = new \DateTimeImmutable('2026-01-01T10:00:00+00:00');
        $released = new \DateTimeImmutable('2026-01-01T11:00:00+00:00');
        $order->reserveInventory($reserved);
        $order->releaseInventory($released);
        $order->releaseInventory(new \DateTimeImmutable('2026-01-01T12:00:00+00:00'));

        self::assertTrue($order->isInventoryReleased());
    }

    private function makeVariant(int $stock = 10): \App\Domain\Catalog\Entity\ProductVariant
    {
        return (new Product(new Category('shoes'), 'runner', 15000))->addVariant('SKU-1', null, $stock);
    }

    private function makeOrder(): Order
    {
        return new Order(
            'SE-2026-00001',
            'customer@example.test',
            '+48 600 000 000',
            DeliveryMethod::Standard,
            15000,
            590,
            'PLN',
            'PL',
            'pl',
        );
    }
}
