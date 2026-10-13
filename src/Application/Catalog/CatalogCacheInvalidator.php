<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Infrastructure\Cache\StorefrontCache;

/**
 * Post-commit catalog invalidation. Stock-affecting writes rotate the
 * list and card generations only — the category tree carries no stock
 * state. Callers invoke this strictly after their transaction commits;
 * rotation itself is best-effort inside StorefrontCache.
 */
class CatalogCacheInvalidator
{
    public function __construct(private readonly StorefrontCache $cache)
    {
    }

    public function productsChanged(): void
    {
        $this->cache->rotate('list', 'card');
    }
}
