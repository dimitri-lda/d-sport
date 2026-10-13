<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Application\Catalog\RefreshCatalogCache;
use App\Infrastructure\Cache\StorefrontCache;
use App\Infrastructure\Catalog\CachedStorefrontCatalog;
use App\Infrastructure\Catalog\RefreshCatalogCacheHandler;
use App\Tests\Double\CollectingMessageBus;
use App\Tests\Double\RecordingStorefrontCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RefreshCatalogCacheHandler::class)]
final class RefreshCatalogCacheHandlerTest extends TestCase
{
    private RecordingStorefrontCatalog $inner;
    private RefreshCatalogCacheHandler $handler;

    protected function setUp(): void
    {
        $this->inner = new RecordingStorefrontCatalog();
        $catalog = new CachedStorefrontCatalog(
            $this->inner,
            new StorefrontCache(
                ['tree' => new ArrayAdapter(), 'list' => new ArrayAdapter(), 'card' => new ArrayAdapter()],
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
            ),
        );
        $this->handler = new RefreshCatalogCacheHandler($catalog, new NullLogger());
    }

    public function testValidMessageRefillsTheEntryIdempotently(): void
    {
        $message = new RefreshCatalogCache('detail', 'en', ['slug' => 'boots']);
        ($this->handler)($message);
        ($this->handler)($message);

        self::assertSame(2, $this->inner->calls['detail']);
    }

    public function testInvalidMessageIsRejectedWithoutARetry(): void
    {
        // Unknown operations and malformed arguments are permanent errors:
        // the handler must swallow them so Messenger does not retry.
        ($this->handler)(new RefreshCatalogCache('nuke', 'en'));
        ($this->handler)(new RefreshCatalogCache('detail', 'en', ['slug' => '']));
        ($this->handler)(new RefreshCatalogCache('cards', 'en', ['ids' => 42]));

        self::assertSame(0, $this->inner->calls['detail']);
        self::assertSame(0, $this->inner->calls['cards']);
    }
}
