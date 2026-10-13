<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Application\Catalog\CatalogCacheInvalidator;
use App\Infrastructure\Cache\StorefrontCache;
use App\Tests\Double\CollectingMessageBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CatalogCacheInvalidator::class)]
final class CatalogCacheInvalidatorTest extends TestCase
{
    public function testProductsChangedRotatesListAndCardOnly(): void
    {
        $loads = 0;
        $pools = ['tree' => new ArrayAdapter(), 'list' => new ArrayAdapter(), 'card' => new ArrayAdapter()];
        $cache = new StorefrontCache(
            $pools,
            [
                'tree' => ['fresh' => 3600, 'total' => 86400],
                'list' => ['fresh' => 300, 'total' => 1800],
                'card' => ['fresh' => 900, 'total' => 3600],
            ],
            true,
            'sf',
            2592000,
            new LockFactory(new InMemoryStore()),
            new CollectingMessageBus(),
            new NullLogger(),
            new MockClock('2026-02-01 12:00:00+00'),
        );
        $loader = static function () use (&$loads): int {
            return ++$loads;
        };
        $cache->remember('tree', 'k', $loader);
        $cache->remember('list', 'k', $loader);
        $cache->remember('card', 'k', $loader);
        self::assertSame(3, $loads);

        (new CatalogCacheInvalidator($cache))->productsChanged();

        // list + card rotated; the category tree carries no stock state and
        // must survive stock-only changes.
        $cache->remember('list', 'k', $loader);
        $cache->remember('card', 'k', $loader);
        $cache->remember('tree', 'k', $loader);
        self::assertSame(5, $loads, 'Exactly the list and card generations rotated.');
    }
}
