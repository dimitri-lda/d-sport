<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog;

use App\Application\Catalog\StorefrontWarmer;
use App\Application\Catalog\WarmStorefrontCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the scheduled warm for every supported locale. Cache writes are
 * idempotent replacements, so a redelivered message simply rewrites the
 * same entries.
 */
#[AsMessageHandler]
final class WarmStorefrontCacheHandler
{
    /** @param list<string> $locales */
    public function __construct(
        private readonly StorefrontWarmer $warmer,
        private readonly array $locales,
    ) {
    }

    public function __invoke(WarmStorefrontCache $message): void
    {
        $this->warmer->warm($this->locales, max(1, $message->pages));
    }
}
