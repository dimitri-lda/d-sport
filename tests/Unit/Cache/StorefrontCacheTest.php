<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cache;

use App\Application\Catalog\RefreshCatalogCache;
use App\Infrastructure\Cache\StorefrontCache;
use App\Tests\Double\CollectingMessageBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(StorefrontCache::class)]
final class StorefrontCacheTest extends TestCase
{
    private const TTL = [
        'tree' => ['fresh' => 3600, 'total' => 86400],
        'list' => ['fresh' => 300, 'total' => 1800],
        'card' => ['fresh' => 900, 'total' => 3600],
    ];

    private CollectingMessageBus $bus;
    private MockClock $clock;

    /** @var array{tree: ArrayAdapter, list: ArrayAdapter, card: ArrayAdapter} */
    private array $pools;

    protected function setUp(): void
    {
        $this->bus = new CollectingMessageBus();
        $this->clock = new MockClock('2026-02-01 12:00:00+00');
        $this->pools = ['tree' => new ArrayAdapter(), 'list' => new ArrayAdapter(), 'card' => new ArrayAdapter()];
    }

    /** @param array{tree: CacheItemPoolInterface, list: CacheItemPoolInterface, card: CacheItemPoolInterface} $pools */
    private function cache(
        array $pools,
        bool $enabled = true,
        ?LockFactory $locks = null,
        ?LoggerInterface $logger = null,
    ): StorefrontCache {
        return new StorefrontCache(
            $pools,
            self::TTL,
            $enabled,
            'sf',
            2592000,
            $locks ?? new LockFactory(new InMemoryStore()),
            $this->bus,
            $logger ?? new NullLogger(),
            $this->clock,
        );
    }

    /** @return list<object> */
    private function messages(): array
    {
        return $this->bus->messages;
    }

    public function testMissFillsOnceAndFreshHitDoesNotReload(): void
    {
        $loads = 0;
        $cache = $this->cache($this->pools);
        $loader = static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        };

        self::assertSame('value-1', $cache->remember('list', 'k1', $loader));
        self::assertSame('value-1', $cache->remember('list', 'k1', $loader));
        self::assertSame(1, $loads, 'A fresh hit must not call the loader.');
        self::assertSame([], $this->messages(), 'No refresh is dispatched inside the fresh window.');
    }

    public function testStaleHitReturnsImmediatelyAndDispatchesDebouncedRefresh(): void
    {
        $loads = 0;
        $cache = $this->cache($this->pools);
        $refresh = new RefreshCatalogCache('page', 'en', ['page' => 1]);
        $loader = static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        };

        $cache->remember('list', 'k1', $loader, $refresh);
        $this->clock->sleep(301); // list fresh TTL is 300s, total 1800s

        self::assertSame('value-1', $cache->remember('list', 'k1', $loader, $refresh));
        self::assertSame(1, $loads, 'A stale hit must not block on the loader.');
        self::assertCount(1, $this->messages());
        self::assertSame($refresh, $this->messages()[0]);

        // Debounce: a second stale hit within the marker window must not requeue.
        $cache->remember('list', 'k1', $loader, $refresh);
        self::assertCount(1, $this->messages());
    }

    public function testRotationOrphansThePreviousGeneration(): void
    {
        $loads = 0;
        $cache = $this->cache($this->pools);
        $loader = static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        };

        self::assertSame('value-1', $cache->remember('card', 'k1', $loader));
        $cache->rotate('card');
        self::assertSame(
            'value-2',
            $cache->remember('card', 'k1', $loader),
            'Rotating the group must make the old entry unreachable.',
        );
        // The unrelated group still serves its entry.
        self::assertSame('value-3', $cache->remember('tree', 'k1', $loader));
        $cache->rotate('card');
        self::assertSame('value-3', $cache->remember('tree', 'k1', $loader));
    }

    public function testFlushRotatesEveryGroup(): void
    {
        $loads = 0;
        $cache = $this->cache($this->pools);
        $loader = static function () use (&$loads): int {
            return ++$loads;
        };
        $cache->remember('tree', 'k', $loader);
        $cache->remember('list', 'k', $loader);
        $cache->remember('card', 'k', $loader);

        $cache->flush();

        $cache->remember('tree', 'k', $loader);
        $cache->remember('list', 'k', $loader);
        $cache->remember('card', 'k', $loader);
        self::assertSame(6, $loads);
    }

    public function testPutWritesAnEntryRememberCanServe(): void
    {
        $cache = $this->cache($this->pools);
        $cache->put('list', 'k1', ['a' => 1]);

        $hit = $cache->remember('list', 'k1', static function (): never {
            throw new \LogicException('A put() entry must be served from cache.');
        });

        self::assertSame(['a' => 1], $hit);
    }

    public function testExpiredEntryBeyondTotalTtlIsAMiss(): void
    {
        $loads = 0;
        $cache = $this->cache($this->pools);
        $loader = static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        };

        $cache->remember('list', 'k1', $loader);
        $this->clock->sleep(1801); // beyond list total TTL

        self::assertSame('value-2', $cache->remember('list', 'k1', $loader));
        self::assertSame([], $this->messages(), 'A hard miss fills inline, no refresh message.');
    }

    public function testPoolFailureDegradesToDirectLoaderCalls(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('Redis is down.'));
        $pools = ['tree' => $pool, 'list' => $this->pools['list'], 'card' => $this->pools['card']];

        $loads = 0;
        $cache = $this->cache($pools);
        $loader = static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        };

        self::assertSame('value-1', $cache->remember('tree', 'k1', $loader));
        self::assertSame('value-2', $cache->remember('tree', 'k1', $loader), 'Nothing is cached while the store is down.');
        // Other groups are unaffected.
        self::assertSame('value-3', $cache->remember('list', 'k1', $loader));
    }

    public function testDisabledCacheAlwaysCallsTheLoader(): void
    {
        $loads = 0;
        $cache = $this->cache($this->pools, enabled: false);
        $loader = static function () use (&$loads): string {
            return 'value-'.++$loads;
        };

        self::assertSame('value-1', $cache->remember('list', 'k1', $loader));
        self::assertSame('value-2', $cache->remember('list', 'k1', $loader));
        $cache->put('list', 'k1', 'ignored');
        self::assertSame('value-3', $cache->remember('list', 'k1', $loader));
    }

    public function testInvalidGroupIsRejected(): void
    {
        $cache = $this->cache($this->pools);
        try {
            $cache->remember('bogus', 'k', static fn (): int => 1);
            self::fail('Unknown group must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('bogus', $exception->getMessage());
        }
        try {
            $cache->rotate('bogus');
            self::fail('Unknown group must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('bogus', $exception->getMessage());
        }
    }

    public function testBusyLockStillReturnsTheLoadedValue(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $cache = $this->cache($this->pools, locks: $locks);

        // Warm a first key so the generation exists, then pre-acquire the
        // exact fill lock for a second key.
        $loader = static fn (): string => 'loaded';
        $cache->remember('list', 'other', $loader);
        $generation = $this->pools['list']->getItem('generation')->get();
        $fullKey = 'sf.list.'.$generation.'.'.hash('sha256', 'contended');
        $holder = $locks->createLock('storefront.'.$fullKey, 30);
        self::assertTrue($holder->acquire());

        $loads = 0;
        $start = microtime(true);
        $value = $cache->remember('list', 'contended', static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        });
        $holder->release();

        self::assertSame('value-1', $value);
        self::assertSame(1, $loads);
        self::assertGreaterThanOrEqual(
            0.4,
            microtime(true) - $start,
            'The busy-lock path must briefly wait for the other fill.',
        );
    }

    public function testBusyGenerationLockNeverInventsACompetingGeneration(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $cache = $this->cache($this->pools, locks: $locks);

        // Another worker holds the generation-init lock for the list group.
        $holder = $locks->createLock('storefront.sf.list.generation', 30);
        self::assertTrue($holder->acquire());

        $loads = 0;
        $value = $cache->remember('list', 'k1', static function () use (&$loads): string {
            ++$loads;

            return 'value-'.$loads;
        });
        $holder->release();

        self::assertSame('value-1', $value, 'The miss still serves the loader result.');
        self::assertFalse(
            $this->pools['list']->getItem('generation')->isHit(),
            'A worker that lost the lock must not publish a competing generation.',
        );
    }

    public function testRepeatedPoolFailureWarnsOncePerOperation(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new \RuntimeException('Redis is down.'));
        $pools = ['tree' => $pool, 'list' => $this->pools['list'], 'card' => $this->pools['card']];

        // The failing generation read is a single operation: repeated calls
        // must produce exactly one warning for the service lifetime.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $cache = $this->cache($pools, logger: $logger);

        $cache->remember('tree', 'k1', static fn (): string => 'a');
        $cache->remember('tree', 'k2', static fn (): string => 'b');
        $cache->remember('tree', 'k3', static fn (): string => 'c');
    }
}
