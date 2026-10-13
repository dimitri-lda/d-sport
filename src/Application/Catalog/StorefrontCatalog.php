<?php

declare(strict_types=1);

namespace App\Application\Catalog;

/**
 * Localized raw read model for the storefront. Rows carry raw net EUR prices
 * and stock; market decoration happens in the application facade.
 */
interface StorefrontCatalog
{
    /** @return list<array<string, mixed>> */
    public function categories(string $locale): array;

    /** @return list<array<string, mixed>> */
    public function featured(string $locale, int $limit = 8): array;

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function page(
        string $locale,
        ?string $query,
        ?string $category,
        ?string $sort,
        int $page,
        int $perPage = 12,
    ): array;

    /** @return array<string, mixed>|null */
    public function detail(string $locale, string $slug): ?array;

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function cards(string $locale, array $ids): array;
}
