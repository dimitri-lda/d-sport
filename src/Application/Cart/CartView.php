<?php

declare(strict_types=1);

namespace App\Application\Cart;

use App\Application\Commerce\PricingService;
use App\Domain\Cart\Entity\Cart;
use App\Domain\Catalog\Entity\ProductImage;
use App\Domain\Commerce\MarketContext;

/**
 * Localized, market-priced cart payload. Legacy pricing semantics: the net
 * unit price is rate-converted with half-up rounding, VAT is applied to the
 * converted net with half-up rounding, and line totals/tax multiply the
 * rounded unit amounts.
 */
final class CartView
{
    public function __construct(private readonly PricingService $pricing)
    {
    }

    /** @return array<string, mixed> */
    public function render(Cart $cart, MarketContext $market): array
    {
        $items = [];
        $count = 0;
        $subtotal = 0;
        $netSubtotal = 0;
        $tax = 0;

        foreach ($cart->getItems() as $item) {
            $variant = $item->getVariant();
            $product = $variant->getProduct();
            $quantity = $item->getQuantity();
            $stock = $variant->getStock();

            $netUnit = $this->pricing->convertedNetCents($variant->getPriceCentsNet(), $market);
            $unit = $this->pricing->grossFromConvertedNetCents($netUnit, $market);
            $lineTotal = $unit * $quantity;
            $lineTax = ($unit - $netUnit) * $quantity;

            $items[] = [
                'id' => $item->getId(),
                'quantity' => $quantity,
                'available' => $product->isActive() && $stock > 0 && $stock >= $quantity,
                'stock' => $stock,
                'product' => [
                    'slug' => $product->getSlug(),
                    'name' => self::localizedName($product->getTranslation($market->locale)?->getName(),
                        $product->getTranslation('en')?->getName(), $product->getSlug()),
                    'image_url' => self::firstImageUrl(array_values($product->getImages()->toArray())),
                ],
                'variant' => [
                    'id' => $variant->getId(),
                    'sku' => $variant->getSku(),
                    'name' => self::localizedName($variant->getTranslation($market->locale)?->getName(),
                        $variant->getTranslation('en')?->getName(), $variant->getSku()),
                ],
                'unit_price_cents' => $unit,
                'line_total_cents' => $lineTotal,
                'net_unit_price_cents' => $netUnit,
                'tax_cents' => $lineTax,
                'currency' => $market->currency,
            ];

            $count += $quantity;
            $subtotal += $lineTotal;
            $netSubtotal += $netUnit * $quantity;
            $tax += $lineTax;
        }

        $quotes = $this->pricing->deliveryQuotes($subtotal, $market);
        $netQuotes = $this->pricing->deliveryNetQuotes($subtotal, $market);

        return [
            'id' => $cart->getId(),
            'count' => $count,
            'items' => $items,
            'summary' => [
                'subtotal_cents' => $subtotal,
                'net_subtotal_cents' => $netSubtotal,
                'tax_cents' => $tax,
                'currency' => $market->currency,
                'delivery_quotes' => [
                    'standard' => $quotes['standard'],
                    'express' => $quotes['express'],
                ],
                'delivery_net_quotes' => [
                    'standard' => $netQuotes['standard'],
                    'express' => $netQuotes['express'],
                ],
                'delivery_tax_quotes' => [
                    'standard' => $quotes['standard'] - $netQuotes['standard'],
                    'express' => $quotes['express'] - $netQuotes['express'],
                ],
                'free_standard_threshold' => $quotes['free_standard_threshold'],
            ],
        ];
    }

    private static function localizedName(?string $requested, ?string $english, string $fallback): string
    {
        return $requested ?? $english ?? $fallback;
    }

    /** @param list<ProductImage> $images */
    private static function firstImageUrl(array $images): ?string
    {
        $first = null;
        foreach ($images as $image) {
            if (null === $first || $image->getPosition() < $first->getPosition()) {
                $first = $image;
            }
        }

        return $first?->getUrl();
    }
}
