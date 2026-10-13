<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Catalog\CachedStorefrontCatalog;

/**
 * Fills the storefront cache synchronously: the category tree, featured
 * cards and the first unfiltered newest pages per supported locale.
 * Locales outside the configured whitelist are ignored; the page bound
 * keeps a runaway schedule from rebuilding the entire catalog.
 */
final class StorefrontWarmer
{
    private const PER_PAGE = 12;
    private const MAX_PAGES = 20;
    private const FEATURED_LIMIT = 8;

    /** @param list<string> $locales */
    public function __construct(
        private readonly CachedStorefrontCatalog $catalog,
        private readonly array $locales,
    ) {
    }

    /** @param list<string> $locales */
    public function warm(array $locales, int $pages): void
    {
        $pages = max(1, min($pages, self::MAX_PAGES));
        $locales = array_values(array_intersect($locales, $this->locales));
        foreach ($locales as $locale) {
            $this->catalog->refresh(new RefreshCatalogCache('categories', $locale));
            $this->catalog->refresh(new RefreshCatalogCache('featured', $locale, [
                'limit' => self::FEATURED_LIMIT,
            ]));
            for ($page = 1; $page <= $pages; ++$page) {
                $this->catalog->refresh(new RefreshCatalogCache('page', $locale, [
                    'query' => null,
                    'category' => null,
                    'sort' => 'newest',
                    'page' => $page,
                    'per_page' => self::PER_PAGE,
                ]));
            }
        }
    }
}
