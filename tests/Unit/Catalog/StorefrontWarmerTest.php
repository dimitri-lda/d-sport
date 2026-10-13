<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Application\Catalog\StorefrontWarmer;
use App\Application\Catalog\WarmStorefrontCache;
use App\Infrastructure\Cache\StorefrontCache;
use App\Infrastructure\Catalog\CachedStorefrontCatalog;
use App\Infrastructure\Catalog\WarmStorefrontCacheHandler;
use App\Tests\Double\CollectingMessageBus;
use App\Tests\Double\RecordingStorefrontCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(StorefrontWarmer::class)]
#[CoversClass(WarmStorefrontCacheHandler::class)]
final class StorefrontWarmerTest extends TestCase
{
    private RecordingStorefrontCatalog $inner;

    protected function setUp(): void
    {
        $this->inner = new RecordingStorefrontCatalog();
    }

    private function catalog(): CachedStorefrontCatalog
    {
        $cache = new StorefrontCache(
            [
                'tree' => new ArrayAdapter(),
                'list' => new ArrayAdapter(),
                'card' => new ArrayAdapter(),
            ],
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

        return new CachedStorefrontCatalog($this->inner, $cache);
    }

    public function testWarmCoversWhitelistedLocalesAndBoundedPages(): void
    {
        $warmer = new StorefrontWarmer($this->catalog(), ['en', 'ru', 'pl']);

        // 'fr' is not a supported locale and must be ignored; 999 pages
        // clamps to 20, 0 clamps up to 1.
        $warmer->warm(['en', 'pl', 'fr'], 3);

        self::assertSame(2, $this->inner->calls['categories']);
        self::assertSame(2, $this->inner->calls['featured']);
        self::assertSame(6, $this->inner->calls['page'], '3 pages × 2 locales.');
        foreach ($this->inner->pageArgs as $args) {
            self::assertSame('newest', $args['sort']);
            self::assertSame(12, $args['per_page']);
            self::assertNull($args['query']);
        }
    }

    public function testWarmPagesAreClampedIntoOneToTwenty(): void
    {
        $warmer = new StorefrontWarmer($this->catalog(), ['en']);
        $warmer->warm(['en'], 0);
        self::assertSame(1, $this->inner->calls['page']);

        $this->inner = new RecordingStorefrontCatalog();
        $warmer = new StorefrontWarmer($this->catalog(), ['en']);
        $warmer->warm(['en'], 100);
        self::assertSame(20, $this->inner->calls['page']);
    }

    public function testHandlerWarmsEveryConfiguredLocale(): void
    {
        $handler = new WarmStorefrontCacheHandler(
            new StorefrontWarmer($this->catalog(), ['en', 'ru', 'pl']),
            ['en', 'ru', 'pl'],
        );

        $handler(new WarmStorefrontCache(1));
        $handler(new WarmStorefrontCache(1)); // redelivery is a harmless rewrite

        self::assertSame(6, $this->inner->calls['categories']);
        self::assertSame(6, $this->inner->calls['page']);
    }
}
