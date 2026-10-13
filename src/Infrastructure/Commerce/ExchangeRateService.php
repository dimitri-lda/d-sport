<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\ExchangeRateFetcher;
use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\ExchangeRateStore;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Domain\Commerce\ExchangeRate;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-through exchange-rate provider. Order of resolution for non-EUR
 * currencies: fresh cache entry, fresh stored quote, an external fetch with
 * upsert, and finally a stale stored quote. A stale fallback is never
 * cached so the next request retries the sources; a missing fallback makes
 * the currency unavailable. Cache failures are logged once per operation
 * and never break a usable stored quote — the service keeps no per-request
 * memoization because workers are long-lived.
 */
final class ExchangeRateService implements ExchangeRateProvider
{
    private const FRESH_TTL = 'PT6H';
    private const EUR_RATE = '1.00000000';

    /** @var array<string, true> */
    private array $warned = [];

    public function __construct(
        private readonly ExchangeRateStore $store,
        private readonly ExchangeRateFetcher $fetcher,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly int $cacheTtlSeconds = 300,
    ) {
    }

    public function rate(string $currency, \DateTimeImmutable $now): ExchangeRate
    {
        $currency = strtoupper($currency);
        if ('EUR' === $currency) {
            return self::eur($now);
        }
        $ttl = new \DateInterval(self::FRESH_TTL);

        $cached = $this->readCache($currency);
        if (null !== $cached && $cached->isFresh($now, $ttl)) {
            return $cached;
        }

        $stored = $this->store->find($currency);
        if (null !== $stored && $stored->isFresh($now, $ttl)) {
            $this->writeCache($stored);

            return $stored;
        }

        try {
            $fresh = $this->fetcher->fetch($currency);
            $this->store->save($fresh);
            $this->writeCache($fresh);

            return $fresh;
        } catch (\Throwable $exception) {
            if (null !== $stored) {
                // A usable stale quote wins over a source outage — and it is
                // deliberately not cached so the next request retries.
                return $stored;
            }

            throw $exception instanceof ExchangeRateUnavailable ? $exception : new ExchangeRateUnavailable($exception->getMessage(), 0, $exception);
        }
    }

    public function refresh(string $currency, \DateTimeImmutable $now): ExchangeRate
    {
        $currency = strtoupper($currency);
        if ('EUR' === $currency) {
            return self::eur($now);
        }

        $fresh = $this->fetcher->fetch($currency);
        $this->store->save($fresh);
        try {
            $this->cache->deleteItem(self::cacheKey($currency));
        } catch (\Throwable $exception) {
            $this->warn('delete', $exception);
        }

        return $fresh;
    }

    private static function eur(\DateTimeImmutable $now): ExchangeRate
    {
        return new ExchangeRate('EUR', self::EUR_RATE, 'base', $now);
    }

    private function readCache(string $currency): ?ExchangeRate
    {
        try {
            $item = $this->cache->getItem(self::cacheKey($currency));
            if (!$item->isHit()) {
                return null;
            }
            $data = $item->get();
            if (!\is_array($data)) {
                return null;
            }

            return new ExchangeRate(
                \is_string($data['currency'] ?? null) ? $data['currency'] : $currency,
                \is_string($data['rate'] ?? null) ? $data['rate'] : '',
                \is_string($data['source'] ?? null) ? $data['source'] : '',
                new \DateTimeImmutable(\is_string($data['quoted_at'] ?? null) ? $data['quoted_at'] : ''),
            );
        } catch (\Throwable $exception) {
            $this->warn('read', $exception);

            return null;
        }
    }

    private function writeCache(ExchangeRate $rate): void
    {
        try {
            $item = $this->cache->getItem(self::cacheKey($rate->currency));
            $item->set([
                'currency' => $rate->currency,
                'rate' => $rate->rate,
                'source' => $rate->source,
                'quoted_at' => $rate->quotedAt->format(\DateTimeInterface::ATOM),
            ]);
            $item->expiresAfter($this->cacheTtlSeconds);
            $this->cache->save($item);
        } catch (\Throwable $exception) {
            $this->warn('write', $exception);
        }
    }

    private static function cacheKey(string $currency): string
    {
        return 'exchange_rate.'.strtolower($currency);
    }

    /**
     * One warning per cache operation for the service lifetime — bounded
     * operational state (operation names are code constants), so a Redis
     * outage cannot flood the log on long-lived workers.
     */
    private function warn(string $operation, \Throwable $exception): void
    {
        if (isset($this->warned[$operation])) {
            return;
        }
        $this->warned[$operation] = true;
        // Safe log shape: operation + exception class only, never payloads.
        $this->logger->warning('Exchange-rate cache operation failed.', [
            'operation' => $operation,
            'exception' => $exception::class,
        ]);
    }
}
