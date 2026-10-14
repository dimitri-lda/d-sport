<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Commerce\MarketContextResolver;
use App\Application\Commerce\PricingService;
use App\Domain\Commerce\MarketContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront-facing catalog facade: resolves the market for the request and
 * decorates raw read-model rows with market-gross prices, currency and
 * availability. Raw net EUR prices never leave this layer.
 */
final class CatalogView
{
    private const HOME_CATEGORY_LIMIT = 4;

    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly MarketContextResolver $resolver,
        private readonly PricingService $pricing,
    ) {
    }

    /** @return array{categories: list<array<string, mixed>>, featured: list<array<string, mixed>>} */
    public function home(Request $request): array
    {
        $market = $this->resolver->resolve($request);

        return [
            'categories' => \array_slice($this->catalog->categories($market->locale), 0, self::HOME_CATEGORY_LIMIT),
            'featured' => $this->decorateCards($this->catalog->featured($market->locale), $market),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function categories(Request $request): array
    {
        return $this->catalog->categories($this->resolver->resolve($request)->locale);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function page(
        Request $request,
        ?string $query,
        ?string $category,
        ?string $sort,
        int $page,
    ): array {
        $market = $this->resolver->resolve($request);
        $result = $this->catalog->page($market->locale, $query, $category, $sort, $page);
        $result['items'] = $this->decorateCards($result['items'], $market);

        return $result;
    }

    /** @return array{product: array<string, mixed>, breadcrumbs: list<array<string, mixed>>, related: list<array<string, mixed>>}|null */
    public function detail(Request $request, string $slug): ?array
    {
        $market = $this->resolver->resolve($request);
        $detail = $this->catalog->detail($market->locale, $slug);
        if (null === $detail) {
            return null;
        }
        $product = self::row($detail['product'] ?? null);
        if ([] === $product) {
            return null;
        }

        return [
            'product' => $this->decorateProduct($product, $market),
            'breadcrumbs' => self::rows($detail['breadcrumbs'] ?? null),
            'related' => $this->decorateCards(self::rows($detail['related'] ?? null), $market),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function decorateCards(array $rows, MarketContext $market): array
    {
        $cards = [];
        foreach ($rows as $row) {
            $cards[] = $this->decorateCard($row, $market);
        }

        return $cards;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decorateCard(array $row, MarketContext $market): array
    {
        $imageUrl = $row['image_url'] ?? null;
        $compareAt = $row['compare_at_price_cents_net'] ?? null;
        $brand = $row['brand'] ?? null;
        $card = [
            'id' => self::toInt($row['id'] ?? null),
            'slug' => self::toString($row['slug'] ?? null),
            'name' => self::toString($row['name'] ?? null),
            'brand' => \is_string($brand) ? $brand : null,
            'image_url' => \is_string($imageUrl) ? $imageUrl : null,
            'price_cents' => $this->pricing->grossCents(self::toInt($row['price_cents_net'] ?? null), $market),
            'currency' => $market->currency,
            'available' => self::toInt($row['stock'] ?? null) > 0,
        ];
        if (null !== $compareAt) {
            $card['compare_at_price_cents'] = $this->pricing->grossCents(self::toInt($compareAt), $market);
        }

        return $card;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decorateProduct(array $row, MarketContext $market): array
    {
        $variants = [];
        foreach (self::rows($row['variants'] ?? null) as $variant) {
            $stock = self::toInt($variant['stock'] ?? null);
            $variants[] = [
                'id' => self::toInt($variant['id'] ?? null),
                'sku' => self::toString($variant['sku'] ?? null),
                'name' => self::toString($variant['name'] ?? null),
                'price_cents' => $this->pricing->grossCents(self::toInt($variant['price_cents_net'] ?? null), $market),
                'currency' => $market->currency,
                'stock' => $stock,
                'available' => $stock > 0,
            ];
        }

        $images = [];
        foreach (self::rows($row['images'] ?? null) as $image) {
            $images[] = [
                'id' => self::toInt($image['id'] ?? null),
                'url' => self::toString($image['url'] ?? null),
                'alt' => \is_string($image['alt'] ?? null) ? $image['alt'] : null,
            ];
        }

        $specifications = [];
        foreach (self::rows($row['specifications'] ?? null) as $spec) {
            $specifications[] = [
                'name' => self::toString($spec['name'] ?? null),
                'value' => self::toString($spec['value'] ?? null),
                'unit' => \is_string($spec['unit'] ?? null) ? $spec['unit'] : null,
            ];
        }

        $brandId = $row['brand_id'] ?? null;
        $manufacturerId = $row['manufacturer_id'] ?? null;
        $compareAt = $row['compare_at_price_cents_net'] ?? null;
        $product = [
            'id' => self::toInt($row['id'] ?? null),
            'slug' => self::toString($row['slug'] ?? null),
            'name' => self::toString($row['name'] ?? null),
            'description' => \is_string($row['description'] ?? null) ? $row['description'] : null,
            'brand' => null === $brandId ? null : [
                'id' => self::toInt($brandId),
                'name' => self::toString($row['brand_name'] ?? null),
                'slug' => self::toString($row['brand_slug'] ?? null),
            ],
            'manufacturer' => null === $manufacturerId ? null : [
                'id' => self::toInt($manufacturerId),
                'name' => self::toString($row['manufacturer_name'] ?? null),
                'slug' => self::toString($row['manufacturer_slug'] ?? null),
            ],
            'price_cents' => $this->pricing->grossCents(self::toInt($row['price_cents_net'] ?? null), $market),
            'currency' => $market->currency,
            'available' => [] !== array_filter($variants, static fn (array $v): bool => true === $v['available']),
            'images' => $images,
            'specifications' => $specifications,
            'variants' => $variants,
        ];
        if (null !== $compareAt) {
            $product['compare_at_price_cents'] = $this->pricing->grossCents(self::toInt($compareAt), $market);
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private static function row(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $row = [];
        foreach ($value as $key => $item) {
            $row[(string) $key] = $item;
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $out[] = self::row($item);
            }
        }

        return $out;
    }

    private static function toInt(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }

        return \is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
