<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Domain\Cart\Entity\Cart;
use App\Domain\Cart\Entity\CartItem;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\DomainException;
use App\Domain\Identity\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cart::class)]
final class CartTest extends TestCase
{
    private static function variant(int $id, int $stock, string $sku = 'SKU-1'): ProductVariant
    {
        $product = new Product(new Category('cat-'.$id), 'product-'.$id, 1000);
        $variant = new ProductVariant($product, $sku.$id, null, $stock);
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, $id);

        return $variant;
    }

    private static function setItemId(CartItem $item, int $id): void
    {
        (new \ReflectionProperty(CartItem::class, 'id'))->setValue($item, $id);
    }

    public function testAddSumsExistingLineAndCapsAtStock(): void
    {
        $cart = Cart::forGuest('token');
        $variant = self::variant(11, 5);

        $cart->addItem($variant, 2);
        $same = $cart->addItem($variant, 3);
        $capped = $cart->addItem($variant, 2);

        self::assertSame(5, $same->getQuantity());
        self::assertSame($same, $capped, 'Repeat adds must hit the same line.');
        self::assertCount(1, $cart->getItems());
    }

    public function testAddCapsAtMaxQuantityEvenWithPlentyStock(): void
    {
        $cart = Cart::forGuest('token');
        $variant = self::variant(12, 500);

        $item = $cart->addItem($variant, 25, 20);

        self::assertSame(20, $item->getQuantity());
    }

    public function testAddOrReplaceOverwritesExistingLine(): void
    {
        $cart = Cart::forGuest('token');
        $variant = self::variant(13, 10);

        $cart->addItem($variant, 7);
        $item = $cart->addOrReplaceItem($variant, 3);

        self::assertSame(3, $item->getQuantity(), 'Merge semantics overwrite, never sum.');
    }

    public function testCartRejectsUserAndGuestTokenTogether(): void
    {
        $reflection = new \ReflectionClass(Cart::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);

        $this->expectException(DomainException::class);
        $constructor->invoke(
            $reflection->newInstanceWithoutConstructor(),
            new User('a@example.test', 'hash', 'A'),
            '00000000-0000-4000-8000-00000000000a',
        );
    }

    public function testAddOrReplaceStillCapsAtStock(): void
    {
        $cart = Cart::forGuest('token');
        $variant = self::variant(14, 4);

        $item = $cart->addOrReplaceItem($variant, 99);

        self::assertSame(4, $item->getQuantity());
    }

    public function testAddRejectsZeroQuantityAndOutOfStock(): void
    {
        $cart = Cart::forGuest('token');

        try {
            $cart->addItem(self::variant(15, 0), 1);
            self::fail('Expected out-of-stock DomainException.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        try {
            $cart->addItem(self::variant(16, 5), 0);
            self::fail('Expected non-positive quantity DomainException.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
    }

    public function testFindItemMatchesByLineId(): void
    {
        $cart = Cart::forGuest('token');
        $item = $cart->addItem(self::variant(17, 9), 2);
        self::setItemId($item, 4321);

        self::assertSame($item, $cart->findItem(4321));
        self::assertNull($cart->findItem(9999));
    }
}
