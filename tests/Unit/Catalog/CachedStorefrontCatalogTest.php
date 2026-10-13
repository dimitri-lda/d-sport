<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Application\Catalog\RefreshCatalogCache;
use App\Infrastructure\Cache\StorefrontCache;
use App\Infrastructure\Catalog\CachedStorefrontCatalog;
use App\Tests\Double\CollectingMessageBus;
use App\Tests\Double\RecordingStorefrontCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CachedStorefrontCatalog::class)]
final class CachedStorefrontCatalogTest extends TestCase
{
    private const TTL = [
        'tree' => ['fresh' => 3600, 'total' => 86400],
        'list' => ['fresh' => 300, 'total' => 1800],
        'card' => ['fresh' => 900, 'total' => 3600],
    ];

    private RecordingStorefrontCatalog $inner;
    private CollectingMessageBus $bus;
    private MockClock $clock;
    private CachedStorefrontCatalog $catalog;

    protected function setUp(): void
    {
        $this->inner = new RecordingStorefrontCatalog();
        $this->bus = new CollectingMessageBus();
        $this->clock = new MockClock('2026-02-01 12:00:00+00');
        $cache = new StorefrontCache(
            ['tree' => new ArrayAdapter(), 'list' => new ArrayAdapter(), 'card' => new ArrayAdapter()],
            self::TTL,
            true,
            'sf',
            2592000,
            new LockFactory(new InMemoryStore()),
            $this->bus,
            new NullLogger(),
            $this->clock,
        );
        $this->catalog = new CachedStorefrontCatalog($this->inner, $cache);
    }

    public function testCategoriesIsCachedUnderTheTreeGroup(): void
    {
        self::assertSame([['name' => 'tree']], $this->catalog->categories('en'));
        self::assertSame([['name' => 'tree']], $this->catalog->categories('en'));
        self::assertSame(1, $this->inner->calls['categories']);
        // A different locale is a different entry.
        $this->catalog->categories('pl');
        self::assertSame(2, $this->inner->calls['categories']);
    }

    public function testPageKeysNormalizeFiltersSortAndBounds(): void
    {
        $this->catalog->page('en', '  boots ', 'shoes', 'bogus', 0);
        $this->catalog->page('en', 'boots', 'shoes', 'newest', 1);
        self::assertSame(
            1,
            $this->inner->calls['page'],
            'Trimmed query, allowlisted sort fallback and clamped page must share one key.',
        );
        $args = $this->inner->pageArgs[0];
        self::assertSame('boots', $args['query']);
        self::assertSame('newest', $args['sort']);
        self::assertSame(1, $args['page']);
    }

    public function testCardsKeySortsIdsSoOrderDoesNotDuplicateEntries(): void
    {
        $this->catalog->cards('en', [3, 1, 2]);
        $this->catalog->cards('en', [1, 3, 2, 1]);
        self::assertSame(1, $this->inner->calls['cards']);
        self::assertSame([1, 2, 3], $this->inner->cardsIds[0], 'The source still gets sorted unique ids.');
    }

    public function testStaleHitServesAndDispatchesExactRefreshMessage(): void
    {
        $this->catalog->page('en', 'boots', null, 'newest', 1);
        $this->clock->sleep(301); // past the list fresh window

        $this->catalog->page('en', 'boots', null, 'newest', 1);
        self::assertSame(1, $this->inner->calls['page'], 'Stale hit must not reload synchronously.');
        self::assertCount(1, $this->bus->messages);
        $message = $this->bus->messages[0];
        self::assertInstanceOf(RefreshCatalogCache::class, $message);
        self::assertSame('page', $message->operation);
        self::assertSame('en', $message->locale);
        self::assertSame([
            'query' => 'boots',
            'category' => null,
            'sort' => 'newest',
            'page' => 1,
            'per_page' => 12,
        ], $message->arguments);
    }

    public function testRefreshLoadsFromSourceAndWritesTheSameKey(): void
    {
        // Seed a stale entry, then let the refresh message refill it.
        $this->catalog->detail('en', 'boots');
        $this->clock->sleep(901); // past the card fresh window

        $this->catalog->refresh(new RefreshCatalogCache('detail', 'en', ['slug' => 'boots']));
        self::assertSame(2, $this->inner->calls['detail']);

        $this->catalog->detail('en', 'boots');
        self::assertSame(2, $this->inner->calls['detail'], 'The refilled entry must be fresh again.');
    }

    public function testRefreshPageAndCardsReloadOnce(): void
    {
        $this->catalog->refresh(new RefreshCatalogCache('page', 'pl', [
            'query' => null, 'category' => null, 'sort' => 'newest', 'page' => 2, 'per_page' => 12,
        ]));
        self::assertSame(1, $this->inner->calls['page']);
        self::assertSame(2, $this->inner->pageArgs[0]['page']);

        $this->catalog->refresh(new RefreshCatalogCache('cards', 'en', ['ids' => '3,1,2']));
        self::assertSame([1, 2, 3], $this->inner->cardsIds[0]);
    }

    public function testUnknownRefreshOperationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->catalog->refresh(new RefreshCatalogCache('drop_table', 'en'));
    }

    public function testHandlerStyleRefreshIsIdempotent(): void
    {
        $message = new RefreshCatalogCache('featured', 'en', ['limit' => 8]);
        $this->catalog->refresh($message);
        $this->catalog->refresh($message);
        self::assertSame(2, $this->inner->calls['featured'], 'Refresh always re-executes the read.');
    }
}
