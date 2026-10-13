<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Cart\CartManager;
use App\Application\Catalog\CatalogCacheInvalidator;
use App\Application\Commerce\CountryCatalog;
use App\Application\Commerce\MarketContextResolver;
use App\Application\Commerce\PricingService;
use App\Domain\Cart\Entity\Cart;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\Commerce\MarketContext;
use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\DeliveryMethod;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Ordering\Entity\OrderAddress;
use App\Presentation\Http\ApiProblem;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Checkout. The whole reservation runs in one transaction: every involved
 * variant is locked pessimistically, re-validated against the pre-lock
 * snapshot (active, price, stock), decremented, then the order — including
 * localized name/price/tax snapshots — and the emptied cart are flushed
 * together. Any failure rolls everything back; `last_order` is only written
 * to the session after a successful commit.
 *
 * A unique-collision on `orders_number_unique` retries the transaction with
 * a fresh candidate up to MAX_ATTEMPTS times; the failed flush closes the
 * entity manager, so each retry resets it and re-resolves the cart — the
 * order owner is taken from that reloaded cart, never from a detached
 * Security user.
 */
final class PlaceOrder
{
    public const SESSION_LAST_ORDER = 'last_order';

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly CartManager $carts,
        private readonly MarketContextResolver $markets,
        private readonly CountryCatalog $countries,
        private readonly PricingService $pricing,
        private readonly OrderNumberGenerator $numbers,
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $registry,
        private readonly CatalogCacheInvalidator $catalogCache,
    ) {
    }

    public function place(Request $request, PlaceOrderInput $input, \DateTimeImmutable $now): Order
    {
        $input = self::validate($input, $this->countries);

        $cart = $this->carts->current($request);
        if ($cart->getItems()->isEmpty()) {
            throw new ApiProblem('cart_empty', 'The cart is empty.', 422, ['cart' => ['The cart must contain at least one item.']]);
        }

        // Selected locale is a read-only borrow of the session preference:
        // forCountry must not mutate it.
        $locale = null;
        if ($request->hasSession()) {
            $preference = $request->getSession()->get(MarketContextResolver::SESSION_KEY);
            if (\is_array($preference) && \is_string($preference['locale'] ?? null)) {
                $locale = $preference['locale'];
            }
        }
        $market = $this->markets->forCountry($input->country, $locale);

        $order = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; ++$attempt) {
            try {
                $order = $this->entityManager->wrapInTransaction(
                    fn (EntityManagerInterface $em): Order => $this->commit(
                        $em, $cart, $market, $input, $cart->getUser(), $now,
                    ),
                );
                break;
            } catch (\Throwable $exception) {
                if ($attempt >= self::MAX_ATTEMPTS || !$this->numbers->isNumberConflict($exception)) {
                    throw $exception;
                }
                // The aborted flush closed the manager: reset it (holders of
                // the lazy service re-resolve a fresh one) and reload the
                // cart so every entity used below is managed again.
                $this->registry->resetManager();
                $cart = $this->carts->current($request);
            }
        }
        \assert($order instanceof Order);

        // Post-commit only: a rolled-back attempt must not invalidate.
        $this->catalogCache->productsChanged();

        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_LAST_ORDER, $order->getNumber());
        }

        return $order;
    }

    private function commit(
        EntityManagerInterface $em,
        Cart $cart,
        MarketContext $market,
        PlaceOrderInput $input,
        ?User $user,
        \DateTimeImmutable $now,
    ): Order {
        // Pre-lock snapshot of every line so the in-lock check can detect a
        // concurrent price change.
        $lines = [];
        foreach ($cart->getItems() as $item) {
            $variant = $item->getVariant();
            $lines[] = [
                'variant_id' => $variant->getId(),
                'quantity' => $item->getQuantity(),
                'net_cents' => $variant->getPriceCentsNet(),
            ];
        }

        /** @var list<array{variant: ProductVariant, quantity: int, net_cents: int}> $locked */
        $locked = [];
        foreach ($lines as $line) {
            $variant = $em->find(ProductVariant::class, $line['variant_id'], LockMode::PESSIMISTIC_WRITE);
            if (null === $variant || !$variant->getProduct()->isActive()) {
                throw self::cartProblem('product_unavailable', 'A product in the cart is no longer available.');
            }
            if ($variant->getPriceCentsNet() !== $line['net_cents']) {
                throw self::cartProblem('price_changed', 'A price in the cart has changed.');
            }
            if ($variant->getStock() < $line['quantity']) {
                throw self::cartProblem('insufficient_stock', 'There is not enough stock for an item in the cart.');
            }
            $locked[] = ['variant' => $variant, 'quantity' => $line['quantity'], 'net_cents' => $line['net_cents']];
        }

        $subtotal = 0;
        $netSubtotal = 0;
        $itemsTax = 0;
        $snapshots = [];
        foreach ($locked as $line) {
            $variant = $line['variant'];
            $netUnit = $this->pricing->convertedNetCents($line['net_cents'], $market);
            $unit = $this->pricing->grossFromConvertedNetCents($netUnit, $market);
            $subtotal += $unit * $line['quantity'];
            $netSubtotal += $netUnit * $line['quantity'];
            $itemsTax += ($unit - $netUnit) * $line['quantity'];
            $snapshots[] = [
                'variant' => $variant,
                'name' => self::localized(
                    $variant->getProduct()->getTranslation($market->locale)?->getName(),
                    $variant->getProduct()->getTranslation('en')?->getName(),
                    $variant->getProduct()->getSlug(),
                ),
                'variant_name' => self::localized(
                    $variant->getTranslation($market->locale)?->getName(),
                    $variant->getTranslation('en')?->getName(),
                    $variant->getSku(),
                ),
                'unit' => $unit,
                'net_unit' => $netUnit,
                'quantity' => $line['quantity'],
                'tax' => ($unit - $netUnit) * $line['quantity'],
            ];
        }

        $method = DeliveryMethod::from($input->deliveryMethod);
        $quotes = $this->pricing->deliveryQuotes($subtotal, $market);
        $deliveryGross = $quotes[$method->value];
        $deliveryNet = $this->pricing->deliveryNetCents($method->value, $subtotal, $market);
        $deliveryTax = $deliveryGross - $deliveryNet;

        $order = new Order(
            $this->numbers->next(),
            $input->email,
            $input->phone,
            $method,
            $subtotal,
            $deliveryGross,
            $market->currency,
            $market->market,
            $market->locale,
            $user,
            'EUR',
            $market->exchangeRate,
            $market->vatBasisPoints,
        );
        $order->setTaxBreakdown($netSubtotal, $itemsTax, $deliveryNet, $deliveryTax);
        $order->setAddress(new OrderAddress(
            $order,
            $input->firstName,
            $input->lastName,
            $input->line1,
            $input->line2,
            $input->city,
            $input->postalCode,
            $input->country,
        ));
        foreach ($snapshots as $snapshot) {
            $order->addItem(
                $snapshot['variant'],
                $snapshot['name'],
                $snapshot['variant_name'],
                $snapshot['unit'],
                $snapshot['quantity'],
                $snapshot['net_unit'],
                $snapshot['tax'],
            );
        }
        $em->persist($order);

        foreach ($locked as $line) {
            $line['variant']->reserve($line['quantity']);
        }
        $order->reserveInventory($now);
        $cart->clear();
        $em->flush();

        return $order;
    }

    private static function localized(?string $requested, ?string $english, string $fallback): string
    {
        return $requested ?? $english ?? $fallback;
    }

    private static function cartProblem(string $code, string $message): ApiProblem
    {
        return new ApiProblem($code, $message, 422, ['cart' => [$message]]);
    }

    private static function validate(PlaceOrderInput $input, CountryCatalog $countries): PlaceOrderInput
    {
        $fields = [];
        $email = mb_strtolower(trim($input->email));
        if ('' === $email || mb_strlen($email) > 320 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = ['A valid email address is required.'];
        }
        $phone = trim($input->phone);
        if ('' === $phone || mb_strlen($phone) > 40) {
            $fields['phone'] = ['The phone number is required (1-40 characters).'];
        }
        $firstName = trim($input->firstName);
        if ('' === $firstName || mb_strlen($firstName) > 80) {
            $fields['first_name'] = ['The first name is required (1-80 characters).'];
        }
        $lastName = trim($input->lastName);
        if ('' === $lastName || mb_strlen($lastName) > 80) {
            $fields['last_name'] = ['The last name is required (1-80 characters).'];
        }
        $line1 = trim($input->line1);
        if ('' === $line1 || mb_strlen($line1) > 180) {
            $fields['line1'] = ['The address line is required (1-180 characters).'];
        }
        $line2 = null === $input->line2 ? null : trim($input->line2);
        if (null !== $line2 && mb_strlen($line2) > 180) {
            $fields['line2'] = ['The second address line must be at most 180 characters.'];
        }
        $city = trim($input->city);
        if ('' === $city || mb_strlen($city) > 80) {
            $fields['city'] = ['The city is required (1-80 characters).'];
        }
        $postalCode = trim($input->postalCode);
        if ('' === $postalCode || mb_strlen($postalCode) > 20) {
            $fields['postal_code'] = ['The postal code is required (1-20 characters).'];
        }
        $country = strtoupper(trim($input->country));
        if (1 !== preg_match('/^[A-Z]{2}$/', $country) || !$countries->isSupported($country)) {
            $fields['country'] = ['The selected country is not supported.'];
        }
        if (!\in_array($input->deliveryMethod, ['standard', 'express'], true)) {
            $fields['delivery_method'] = ['The delivery method must be standard or express.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given order data is invalid.', 422, $fields);
        }

        return new PlaceOrderInput(
            $email,
            $phone,
            $firstName,
            $lastName,
            $line1,
            '' === (string) $line2 ? null : $line2,
            $city,
            $postalCode,
            $country,
            $input->deliveryMethod,
        );
    }
}
