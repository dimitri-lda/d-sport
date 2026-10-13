<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateStore;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Domain\Commerce\ExchangeRate;
use App\Infrastructure\Commerce\ExchangeRateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[CoversClass(ExchangeRateService::class)]
final class ExchangeRateServiceTest extends TestCase
{
    private const NOW = '2026-02-01T12:00:00+00:00';

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }

    private static function stored(string $rate = '4.28000000', string $quotedAt = self::NOW): ExchangeRate
    {
        return new ExchangeRate('PLN', $rate, 'store', new \DateTimeImmutable($quotedAt));
    }

    /** @param list<ExchangeRate> $fetched */
    private function fetcher(array $fetched): ExchangeRateFetcher
    {
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->method('fetch')->willReturnOnConsecutiveCalls(...$fetched);

        return $fetcher;
    }

    private function store(?ExchangeRate $rate): ExchangeRateStore
    {
        $store = $this->createStub(ExchangeRateStore::class);
        $store->method('find')->willReturn($rate);

        return $store;
    }

    private function service(
        ExchangeRateStore $store,
        ExchangeRateFetcher $fetcher,
        ?CacheItemPoolInterface $cache = null,
        ?LoggerInterface $logger = null,
    ): ExchangeRateService {
        return new ExchangeRateService(
            $store,
            $fetcher,
            $cache ?? new ArrayAdapter(),
            $logger ?? new NullLogger(),
        );
    }

    public function testEurIsSyntheticAndNeverHitsStoreOrSources(): void
    {
        $store = $this->createMock(ExchangeRateStore::class);
        $store->expects(self::never())->method('find');
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->expects(self::never())->method('fetch');

        $rate = $this->service($store, $fetcher)->rate('eur', self::now());

        self::assertSame('1.00000000', $rate->rate);
        self::assertSame('base', $rate->source);
    }

    public function testFreshStoredQuoteIsReturnedAndCached(): void
    {
        $stored = self::stored(quotedAt: '2026-02-01T09:00:00+00:00'); // 3h old
        $store = $this->createMock(ExchangeRateStore::class);
        $store->expects(self::once())->method('find')->willReturn($stored);
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->expects(self::never())->method('fetch');

        $service = $this->service($store, $fetcher);

        self::assertSame($stored, $service->rate('PLN', self::now()));
        // Second call must come from the cache write: the store is not hit again.
        self::assertSame($stored->rate, $service->rate('PLN', self::now())->rate);
    }

    public function testStaleStoredQuoteFetchesUpsertsAndCaches(): void
    {
        $stored = self::stored(quotedAt: '2026-02-01T05:00:00+00:00'); // 7h old
        $fresh = new ExchangeRate('PLN', '4.30000000', 'nbp', self::now());

        $store = $this->createMock(ExchangeRateStore::class);
        $store->method('find')->willReturn($stored);
        $store->expects(self::once())->method('save')->with($fresh);

        $service = $this->service($store, $this->fetcher([$fresh]));
        $rate = $service->rate('PLN', self::now());

        self::assertSame('4.30000000', $rate->rate);
        self::assertSame('nbp', $rate->source);
    }

    public function testStaleFallbackIsReturnedButNeverCached(): void
    {
        $stored = self::stored(quotedAt: '2026-02-01T05:00:00+00:00'); // stale
        $store = $this->createMock(ExchangeRateStore::class);
        $store->expects(self::exactly(2))->method('find')->willReturn($stored);
        $store->expects(self::never())->method('save');
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        // Both calls fail: the fallback is served each time and every new
        // request retries the sources because nothing was cached.
        $fetcher->expects(self::exactly(2))->method('fetch')
            ->willThrowException(new ExchangeRateUnavailable('all sources down'));

        $service = $this->service($store, $fetcher);

        self::assertSame($stored, $service->rate('PLN', self::now()));
        self::assertSame($stored, $service->rate('PLN', self::now()));
    }

    public function testNoStoredQuoteAndFailedSourcesIsUnavailable(): void
    {
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->method('fetch')->willThrowException(new ExchangeRateUnavailable('down'));

        $this->expectException(ExchangeRateUnavailable::class);
        $this->service($this->store(null), $fetcher)->rate('PLN', self::now());
    }

    public function testSixHourBoundary(): void
    {
        // Exactly 6h old is still fresh — no fetch.
        $boundary = self::stored(quotedAt: '2026-02-01T06:00:00+00:00');
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->expects(self::never())->method('fetch');
        self::assertSame(
            $boundary,
            $this->service($this->store($boundary), $fetcher)->rate('PLN', self::now()),
        );

        // One second older is stale: a fetch must happen.
        $stale = self::stored(quotedAt: '2026-02-01T05:59:59+00:00');
        $fresh = new ExchangeRate('PLN', '4.31000000', 'nbp', self::now());
        $store = $this->createStub(ExchangeRateStore::class);
        $store->method('find')->willReturn($stale);
        self::assertSame(
            '4.31000000',
            $this->service($store, $this->fetcher([$fresh]))->rate('PLN', self::now())->rate,
        );
    }

    public function testCacheReadFailureFallsThroughToStore(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Redis is down.'));

        $stored = self::stored(quotedAt: '2026-02-01T09:00:00+00:00');
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->expects(self::never())->method('fetch');

        $rate = $this->service($this->store($stored), $fetcher, $cache)->rate('PLN', self::now());

        self::assertSame($stored, $rate, 'Redis down must never break a usable stored quote.');
    }

    public function testCacheWriteFailureStillReturnsTheQuote(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willThrowException(new \RuntimeException('Redis is down.'));

        $stored = self::stored(quotedAt: '2026-02-01T09:00:00+00:00');
        $rate = $this->service($this->store($stored), $this->fetcher([]), $cache)->rate('PLN', self::now());

        self::assertSame($stored, $rate);
    }

    public function testRefreshForceFetchesUpsertsAndDeletesCache(): void
    {
        $fresh = new ExchangeRate('PLN', '4.32000000', 'walutomat', self::now());
        $store = $this->createMock(ExchangeRateStore::class);
        $store->expects(self::once())->method('save')->with($fresh);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())->method('deleteItem')->with('exchange_rate.pln');

        $service = $this->service($store, $this->fetcher([$fresh]), $cache);
        $rate = $service->refresh('PLN', self::now());

        self::assertSame('4.32000000', $rate->rate);
    }

    public function testRefreshPropagatesUnavailability(): void
    {
        $fetcher = $this->createMock(ExchangeRateFetcher::class);
        $fetcher->method('fetch')->willThrowException(new ExchangeRateUnavailable('down'));
        $store = $this->createMock(ExchangeRateStore::class);
        $store->expects(self::never())->method('save');

        $this->expectException(ExchangeRateUnavailable::class);
        $this->service($store, $fetcher)->refresh('PLN', self::now());
    }

    public function testRepeatedCacheFailureWarnsOncePerOperation(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Redis is down.'));

        // Repeated calls fail the same two operations (read + write): each
        // is warned once for the service lifetime, never per request.
        $operations = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning')
            ->willReturnCallback(static function (string $message, array $context) use (&$operations): void {
                $operations[] = $context['operation'] ?? null;
            });
        $stored = self::stored(quotedAt: '2026-02-01T09:00:00+00:00');

        $service = $this->service($this->store($stored), $this->fetcher([]), $cache, $logger);
        self::assertSame($stored, $service->rate('PLN', self::now()));
        self::assertSame($stored, $service->rate('PLN', self::now()));
        self::assertSame($stored, $service->rate('PLN', self::now()));
        self::assertSame(['read', 'write'], $operations, 'Each operation warns once, in order.');
    }
}
