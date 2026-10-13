<?php

declare(strict_types=1);

namespace App\Application\Catalog;

/**
 * Scheduled trigger: warm the default storefront cache entries (category
 * tree, featured cards and the first unfiltered catalog pages) for every
 * supported locale.
 */
final readonly class WarmStorefrontCache
{
    public function __construct(public int $pages = 2)
    {
    }
}
