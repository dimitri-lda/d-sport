<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Application\Cart\CartManager;
use App\Domain\Cart\Entity\Cart;
use App\Domain\Cart\Entity\CartItem;
use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Entity\Product;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Catalog\Repository\ProductVariantRepository;
use App\Domain\Identity\Entity\User;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(CartManager::class)]
final class CartManagerTest extends TestCase
{
    private CartRepository $carts;
    private ProductVariantRepository $variants;
    private EntityManagerInterface $em;
    private Security $security;
    private CartManager $manager;

    protected function setUp(): void
    {
        $this->carts = $this->createMock(CartRepository::class);
        $this->variants = $this->createMock(ProductVariantRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(
            fn (callable $callback): mixed => $callback($this->em),
        );
        $this->security = $this->createMock(Security::class);
        $this->manager = new CartManager($this->carts, $this->variants, $this->em, $this->security);
    }

    private static function variant(int $id, int $stock): ProductVariant
    {
        $product = new Product(new Category('cat-'.$id), 'product-'.$id, 1000);
        $variant = new ProductVariant($product, 'SKU-'.$id, null, $stock);
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, $id);

        return $variant;
    }

    private static function requestWithSession(): Request
    {
        $request = Request::create('/api/v1/cart');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testGuestCartIsCreatedAndTokenStoredInSession(): void
    {
        $request = self::requestWithSession();
        $this->security->method('getUser')->willReturn(null);
        $this->carts->expects(self::never())->method('findForGuestToken'); // no token yet
        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Cart::class));

        $cart = $this->manager->current($request);

        self::assertNull($cart->getUser());
        $token = $request->getSession()->get(CartManager::SESSION_KEY);
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token);
        self::assertSame($token, $cart->getGuestToken());
    }

    public function testGuestCartIsResolvedByExistingSessionToken(): void
    {
        $request = self::requestWithSession();
        $request->getSession()->set(CartManager::SESSION_KEY, 'existing-token');
        $this->security->method('getUser')->willReturn(null);
        $existing = Cart::forGuest('existing-token');
        $this->carts->expects(self::once())->method('findForGuestToken')
            ->with('existing-token')->willReturn($existing);
        $this->em->expects(self::never())->method('persist');

        self::assertSame($existing, $this->manager->current($request));
    }

    public function testUpdateForeignItemYieldsGeneric404(): void
    {
        $request = self::requestWithSession();
        $this->security->method('getUser')->willReturn(null);
        $this->carts->method('findForGuestToken')->willReturn(null); // force fresh cart, no items

        try {
            $this->manager->update($request, 12345, 2);
            self::fail('Expected cart_item_not_found.');
        } catch (ApiProblem $problem) {
            self::assertSame('cart_item_not_found', $problem->errorCode);
            self::assertSame(404, $problem->status);
        }
    }

    public function testUpdateZeroRemovesLine(): void
    {
        $request = self::requestWithSession();
        $this->security->method('getUser')->willReturn(null);
        $variant = self::variant(31, 5);
        $cart = Cart::forGuest('token');
        $item = $cart->addItem($variant, 2);
        (new \ReflectionProperty(CartItem::class, 'id'))->setValue($item, 77);
        $this->carts->method('findForGuestToken')->willReturn($cart);
        $request->getSession()->set(CartManager::SESSION_KEY, 'token');

        $this->manager->update($request, 77, 0);

        self::assertCount(0, $cart->getItems());
    }

    public function testMergeOverwritesUserLineWithGuestQuantity(): void
    {
        $user = new User('merge@example.test', 'hash', 'Merge');
        $variant = self::variant(41, 10);
        $guest = Cart::forGuest('guest-tok');
        $guest->addItem($variant, 3);
        $target = Cart::forUser($user);
        $target->addItem($variant, 1);

        $this->carts->method('findForGuestToken')->with('guest-tok')->willReturn($guest);
        $this->carts->method('findForUser')->with($user)->willReturn($target);
        $this->em->expects(self::once())->method('remove')->with($guest);
        $this->em->expects(self::never())->method('persist');

        $session = new Session(new MockArraySessionStorage());
        $session->set(CartManager::SESSION_KEY, 'guest-tok');
        $this->manager->mergeGuestIntoUser($session, $user);

        self::assertSame(3, $target->getItems()->first()->getQuantity(), 'Guest lines overwrite, never sum.');
        self::assertNull($session->get(CartManager::SESSION_KEY));
    }

    public function testMergeDropsOutOfStockLineAndCreatesMissingUserCart(): void
    {
        $user = new User('merge2@example.test', 'hash', 'Merge Two');
        $dead = self::variant(51, 0);
        $live = self::variant(52, 4);
        $guest = Cart::forGuest('guest-tok-2');
        $guest->addItem($live, 9); // merges capped at stock 4

        // A dead variant cannot enter a cart via the public API; inject it.
        $guest->getItems()->add(new CartItem($guest, $dead, 1));

        $this->carts->method('findForGuestToken')->with('guest-tok-2')->willReturn($guest);
        $this->carts->method('findForUser')->with($user)->willReturn(null);
        $target = null;
        $this->em->expects(self::once())->method('persist')
            ->with(self::isInstanceOf(Cart::class))
            ->willReturnCallback(static function (Cart $cart) use (&$target): void {
                $target = $cart;
            });

        $session = new Session(new MockArraySessionStorage());
        $session->set(CartManager::SESSION_KEY, 'guest-tok-2');
        $this->manager->mergeGuestIntoUser($session, $user);

        self::assertInstanceOf(Cart::class, $target);
        self::assertSame($user, $target->getUser());
        self::assertCount(1, $target->getItems(), 'The zero-stock guest line must be dropped.');
        self::assertSame(4, $target->getItems()->first()->getQuantity());
        self::assertNull($session->get(CartManager::SESSION_KEY));
    }
}
