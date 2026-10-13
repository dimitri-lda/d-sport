<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog;

use App\Application\Catalog\RefreshCatalogCache;
use App\Application\Catalog\StorefrontCatalog;
use App\Infrastructure\Cache\StorefrontCache;

/**
 * Caching decorator over the DBAL read model. Logical keys are canonical
 * descriptors of locale + normalized arguments (hashed by the cache), so
 * equivalent queries share one entry. Stale hits dispatch a
 * RefreshCatalogCache describing the exact operation needed to refill.
 */
final class CachedStorefrontCatalog implements StorefrontCatalog
{
    private const MAX_FEATURED = 8;
    private const SORTS = ['newest', 'price_asc', 'price_desc'];
    private const GROUPS = [
        'categories' => 'tree',
        'featured' => 'list',
        'page' => 'list',
        'detail' => 'card',
        'cards' => 'card',
    ];

    public function __construct(
        private readonly StorefrontCatalog $inner,
        private readonly StorefrontCache $cache,
    ) {
    }

    public function categories(string $locale): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = $this->cache->remember(
            'tree',
            self::keyFor('categories', $locale),
            fn (): array => $this->inner->categories($locale),
            new RefreshCatalogCache('categories', $locale),
        );

        return $result;
    }

    public function featured(string $locale, int $limit = 8): array
    {
        $limit = max(1, min($limit, self::MAX_FEATURED));

        /** @var list<array<string, mixed>> $result */
        $result = $this->cache->remember(
            'list',
            self::keyFor('featured', $locale, ['limit' => $limit]),
            fn (): array => $this->inner->featured($locale, $limit),
            new RefreshCatalogCache('featured', $locale, ['limit' => $limit]),
        );

        return $result;
    }

    public function page(
        string $locale,
        ?string $query,
        ?string $category,
        ?string $sort,
        int $page,
        int $perPage = 12,
    ): array {
        $args = self::pageArguments($query, $category, $sort, $page, $perPage);

        /** @var array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} $result */
        $result = $this->cache->remember(
            'list',
            self::keyFor('page', $locale, $args),
            fn (): array => $this->inner->page(
                $locale, $args['query'], $args['category'], $args['sort'], $args['page'], $args['per_page'],
            ),
            new RefreshCatalogCache('page', $locale, $args),
        );

        return $result;
    }

    public function detail(string $locale, string $slug): ?array
    {
        /** @var array<string, mixed>|null $result */
        $result = $this->cache->remember(
            'card',
            self::keyFor('detail', $locale, ['slug' => $slug]),
            fn (): ?array => $this->inner->detail($locale, $slug),
            new RefreshCatalogCache('detail', $locale, ['slug' => $slug]),
        );

        return $result;
    }

    public function cards(string $locale, array $ids): array
    {
        $ids = self::normalizeIds($ids);
        if ([] === $ids) {
            return [];
        }
        $idList = implode(',', array_map('strval', $ids));

        /** @var list<array<string, mixed>> $result */
        $result = $this->cache->remember(
            'card',
            self::keyFor('cards', $locale, ['ids' => $idList]),
            fn (): array => $this->inner->cards($locale, $ids),
            new RefreshCatalogCache('cards', $locale, ['ids' => $idList]),
        );

        return $result;
    }

    /**
     * Refill one entry from the source read model. The operation allowlist
     * keeps async messages safe: an unknown operation is rejected before
     * any store access.
     */
    public function refresh(RefreshCatalogCache $message): void
    {
        $operation = $message->operation;
        if (!isset(self::GROUPS[$operation])) {
            throw new \InvalidArgumentException('Unknown catalog refresh operation "'.$operation.'".');
        }
        $locale = $message->locale;
        $args = $message->arguments;

        match ($operation) {
            'categories' => $this->put(
                $operation, $locale, [],
                $this->inner->categories($locale),
            ),
            'featured' => $this->put(
                $operation, $locale, ['limit' => $limit = self::intArg($args, 'limit', 8)],
                $this->inner->featured($locale, $limit),
            ),
            'page' => $this->put(
                $operation, $locale, $page = self::pageArguments(
                    self::stringArg($args, 'query'),
                    self::stringArg($args, 'category'),
                    self::stringArg($args, 'sort'),
                    self::intArg($args, 'page', 1),
                    self::intArg($args, 'per_page', 12),
                ),
                $this->inner->page(
                    $locale, $page['query'], $page['category'], $page['sort'], $page['page'], $page['per_page'],
                ),
            ),
            'detail' => $this->put(
                $operation, $locale, ['slug' => $slug = self::slugArg($args)],
                $this->inner->detail($locale, $slug),
            ),
            'cards' => $this->put(
                $operation, $locale,
                ['ids' => $idList = implode(',', array_map('strval', $ids = self::idsArg($args)))],
                $this->inner->cards($locale, $ids),
            ),
        };
    }

    /** @param array<string, int|string|null> $args */
    private function put(string $operation, string $locale, array $args, mixed $value): void
    {
        $this->cache->put(self::GROUPS[$operation], self::keyFor($operation, $locale, $args), $value);
    }

    /** @param array<string, int|string|null> $args */
    private static function keyFor(string $operation, string $locale, array $args = []): string
    {
        $key = $operation.'|'.$locale;
        foreach ($args as $name => $value) {
            $key .= '|'.$name.'='.($value ?? '');
        }

        return $key;
    }

    /** @return array{query: ?string, category: ?string, sort: string, page: int, per_page: int} */
    private static function pageArguments(
        ?string $query,
        ?string $category,
        ?string $sort,
        int $page,
        int $perPage,
    ): array {
        return [
            'query' => self::blankToNull($query),
            'category' => self::blankToNull($category),
            'sort' => \in_array($sort, self::SORTS, true) ? $sort : 'newest',
            'page' => max(1, $page),
            'per_page' => max(1, min($perPage, 48)),
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return null === $value || '' === $value ? null : $value;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    /** @param array<string, int|string|null> $args */
    private static function stringArg(array $args, string $name): ?string
    {
        $value = $args[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    /** @param array<string, int|string|null> $args */
    private static function intArg(array $args, string $name, int $default): int
    {
        $value = $args[$name] ?? null;
        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && \is_numeric($value) ? (int) $value : $default;
    }

    /** @param array<string, int|string|null> $args */
    private static function slugArg(array $args): string
    {
        $slug = $args['slug'] ?? null;
        if (!\is_string($slug) || '' === $slug) {
            throw new \InvalidArgumentException('A detail refresh needs a slug.');
        }

        return $slug;
    }

    /**
     * @param array<string, int|string|null> $args
     *
     * @return list<int>
     */
    private static function idsArg(array $args): array
    {
        $ids = $args['ids'] ?? null;
        if (!\is_string($ids)) {
            throw new \InvalidArgumentException('A cards refresh needs a comma-separated id list.');
        }

        return self::normalizeIds('' === $ids ? [] : array_map('intval', explode(',', $ids)));
    }
}
