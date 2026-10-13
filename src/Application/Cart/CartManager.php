<?php

declare(strict_types=1);

namespace App\Application\Cart;

use App\Domain\Cart\Entity\Cart;
use App\Domain\Cart\Repository\CartRepository;
use App\Domain\Catalog\Repository\ProductVariantRepository;
use App\Domain\Identity\Entity\User;
use App\Presentation\Http\ApiProblem;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cart resolution and mutations. Authenticated users own a cart by relation;
 * guests carry a `cart_token` session key holding a v4 UUID. Ownership
 * mismatches and missing lines always surface as a generic 404 so neither
 * cart nor item ids leak across ownership boundaries.
 */
final class CartManager
{
    public const SESSION_KEY = 'cart_token';

    private const MAX_QUANTITY = 20;

    public function __construct(
        private readonly CartRepository $carts,
        private readonly ProductVariantRepository $variants,
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function current(Request $request): Cart
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $cart = $this->carts->findForUser($user);
            if (null !== $cart) {
                return $cart;
            }

            return $this->create(Cart::forUser($user), $user, null);
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        $token = $session?->get(self::SESSION_KEY);
        if (\is_string($token) && '' !== $token) {
            $cart = $this->carts->findForGuestToken($token);
            if (null !== $cart) {
                return $cart;
            }
        }

        $token = Uuid::v4()->toRfc4122();
        $cart = $this->create(Cart::forGuest($token), null, $token);
        $session?->set(self::SESSION_KEY, $token);

        return $cart;
    }

    public function add(Request $request, int $variantId, int $quantity): Cart
    {
        $variant = $this->variants->findSellable($variantId);
        if (null === $variant) {
            throw new ApiProblem('product_variant_not_found', 'The product variant was not found.', 404);
        }
        if ($variant->getStock() <= 0) {
            throw self::outOfStock();
        }
        $cart = $this->current($request);
        $cart->addItem($variant, $quantity, self::MAX_QUANTITY);
        $this->entityManager->flush();

        return $cart;
    }

    public function update(Request $request, int $itemId, int $quantity): Cart
    {
        $cart = $this->current($request);
        $item = $cart->findItem($itemId);
        if (null === $item) {
            throw self::itemNotFound();
        }
        if (0 === $quantity) {
            $cart->removeItem($item);
        } else {
            $stock = $item->getVariant()->getStock();
            if ($stock <= 0) {
                throw self::outOfStock();
            }
            $item->setQuantity(min($quantity, $stock, self::MAX_QUANTITY));
        }
        $this->entityManager->flush();

        return $cart;
    }

    public function remove(Request $request, int $itemId): Cart
    {
        $cart = $this->current($request);
        $item = $cart->findItem($itemId);
        if (null === $item) {
            throw self::itemNotFound();
        }
        $cart->removeItem($item);
        $this->entityManager->flush();

        return $cart;
    }

    public function clear(Cart $cart): void
    {
        $cart->clear();
        $this->entityManager->flush();
    }

    /**
     * Login/register merge: every guest line overwrites the user cart's line
     * for that variant (never sums), capped by current stock and the max
     * quantity. Lines whose variant has no stock left are dropped. The guest
     * cart and the session token are removed in the same transaction.
     */
    public function mergeGuestIntoUser(SessionInterface $session, User $user): void
    {
        $token = $session->get(self::SESSION_KEY);
        if (!\is_string($token) || '' === $token) {
            return;
        }
        $guest = $this->carts->findForGuestToken($token);

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($guest, $user, $session): void {
            $target = $this->carts->findForUser($user);
            if (null === $target) {
                $target = Cart::forUser($user);
                $em->persist($target);
            }
            if (null !== $guest) {
                foreach ($guest->getItems() as $item) {
                    $variant = $item->getVariant();
                    $cap = min($variant->getStock(), self::MAX_QUANTITY);
                    if ($cap <= 0) {
                        continue; // out-of-stock line: dropped rather than blocking login
                    }
                    $target->addOrReplaceItem($variant, min($item->getQuantity(), $cap), self::MAX_QUANTITY);
                }
                $em->remove($guest);
            }
            $em->flush();
            $session->remove(self::SESSION_KEY);
        });
    }

    private function create(Cart $cart, ?User $user, ?string $token): Cart
    {
        $this->entityManager->persist($cart);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the create race; reload the winner.
            $this->entityManager->clear();
            $cart = null !== $user
                ? $this->carts->findForUser($user)
                : (null !== $token ? $this->carts->findForGuestToken($token) : null);
            if (null === $cart) {
                throw new ApiProblem('cart_unavailable', 'The cart could not be resolved.', 500);
            }
        }

        return $cart;
    }

    private static function outOfStock(): ApiProblem
    {
        return new ApiProblem('out_of_stock', 'This product is out of stock.', 422, [
            'quantity' => ['The requested quantity is not in stock.'],
        ]);
    }

    private static function itemNotFound(): ApiProblem
    {
        return new ApiProblem('cart_item_not_found', 'The cart item was not found.', 404);
    }
}
