<?php

declare(strict_types=1);

namespace App\Application\Catalog;

/**
 * Async catalog-cache refresh request for one allowlisted operation. The
 * handler re-executes the read against the source read model and replaces
 * the cached entry — a stale-while-revalidate fill, fully idempotent.
 */
final readonly class RefreshCatalogCache
{
    public const OPERATIONS = ['categories', 'featured', 'page', 'detail', 'cards'];

    /** @param array<string, int|string|null> $arguments */
    public function __construct(
        public string $operation,
        public string $locale,
        public array $arguments = [],
    ) {
    }
}
