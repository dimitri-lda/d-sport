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
 * upsert, and finally a stale stored quote — but only while it is younger
 * than MAX_STALE; beyond that ceiling the currency goes unavailable rather
 * than pricing orders off an arbitrarily old rate. A fetched quote that
 * deviates from a still-usable stored quote by more than
 * MAX_DEVIATION_BPS is rejected as implausible; once the stored quote ages
 * out of the stale window a genuine large move is accepted again. A stale
 * fallback is never cached so the next request retries the sources.
 * Cache failures are rate-limited warnings and never break a usable
 * stored quote — the service keeps no per-request memoization because
 * workers are long-lived.
 */
final class ExchangeRateService implements ExchangeRateProvider
{
    private const FRESH_TTL = 'PT6H';
    private const MAX_STALE = 'P7D';
    private const MAX_DEVIATION_BPS = 2000;
    private const WARN_INTERVAL_SECONDS = 300;
    private const EUR_RATE = '1.00000000';

    /** @var array<string, int> operation name => last warning timestamp */
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

        $maxStale = new \DateInterval(self::MAX_STALE);
        try {
            $fresh = $this->fetcher->fetch($currency);
            if (null !== $stored && $stored->isFresh($now, $maxStale) && self::deviates($stored->rate, $fresh->rate)) {
                // An implausible jump while a stored quote is still usable:
                // keep the stored one — not saved, not cached — so the next
                // request retries the sources. Once the stored quote ages
                // out, a genuine large move is accepted again.
                $this->logger->warning('Rejected an implausible exchange-rate jump.', [
                    'currency' => $currency,
                    'stored' => $stored->rate,
                    'fetched' => $fresh->rate,
                ]);

                return $stored;
            }
            $this->store->save($fresh);
            $this->writeCache($fresh);

            return $fresh;
        } catch (\Throwable $exception) {
            if (null !== $stored && $stored->isFresh($now, $maxStale)) {
                // A usable stale quote wins over a source outage — and it is
                // deliberately not cached so the next request retries.
                $this->logger->warning('Serving a stale exchange-rate quote after a fetch failure.', [
                    'currency' => $currency,
                    'age_hours' => intdiv($now->getTimestamp() - $stored->quotedAt->getTimestamp(), 3600),
                ]);

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
        $stored = $this->store->find($currency);
        if (
            null !== $stored
            && $stored->isFresh($now, new \DateInterval(self::MAX_STALE))
            && self::deviates($stored->rate, $fresh->rate)
        ) {
            // Surface the implausible jump to the command/scheduler instead
            // of silently persisting it.
            $this->logger->warning('Rejected an implausible exchange-rate jump.', [
                'currency' => $currency,
                'stored' => $stored->rate,
                'fetched' => $fresh->rate,
            ]);

            throw new ExchangeRateUnavailable(\sprintf('The fetched %s rate %s deviates from the stored quote %s by more than %d%%.', $currency, $fresh->rate, $stored->rate, intdiv(self::MAX_DEVIATION_BPS, 100)));
        }
        $this->store->save($fresh);
        try {
            $this->cache->deleteItem(self::cacheKey($currency));
        } catch (\Throwable $exception) {
            $this->warn('delete', $exception);
        }

        return $fresh;
    }

    /**
     * Whether the fetched rate deviates from the stored one by more than
     * MAX_DEVIATION_BPS. Integer math on the canonical 8-decimal strings —
     * deviation > 20% is equivalent to diff * (10000/2000) > stored, i.e.
     * diff * 5 > stored, which stays inside int64 for the numeric(18,8)
     * domain. Never a float on the money path.
     */
    private static function deviates(string $storedRate, string $fetchedRate): bool
    {
        $stored = self::scaledRate($storedRate);
        if (0 === $stored) {
            return false; // no baseline to compare against
        }

        return abs(self::scaledRate($fetchedRate) - $stored) * 5 > $stored;
    }

    /** Parses a canonical decimal string into an 8-decimal scaled integer. */
    private static function scaledRate(string $rate): int
    {
        [$integer, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return ((int) $integer) * 100000000 + (int) str_pad($fraction, 8, '0');
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
            // A malformed entry is rejected outright: a missing quoted_at
            // would otherwise parse as "now" and masquerade as a fresh rate.
            $rate = $data['rate'] ?? null;
            $entryCurrency = $data['currency'] ?? null;
            $source = $data['source'] ?? null;
            $quotedAt = $data['quoted_at'] ?? null;
            if (
                !\is_string($rate) || !\is_string($entryCurrency)
                || !\is_string($source) || !\is_string($quotedAt)
                // Same positive-decimal shape PricingService::scaleRate accepts.
                || 1 !== preg_match('/^\d+(\.\d{1,8})?$/', $rate)
            ) {
                return null;
            }
            try {
                $at = new \DateTimeImmutable($quotedAt);
            } catch (\Throwable) {
                return null;
            }

            return new ExchangeRate($entryCurrency, $rate, $source, $at);
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
     * At most one warning per cache operation per WARN_INTERVAL_SECONDS —
     * a Redis outage cannot flood the log on long-lived workers, but a
     * persistent outage still re-surfaces instead of staying silent
     * forever. Bounded operational state (operation names are constants).
     */
    private function warn(string $operation, \Throwable $exception): void
    {
        $now = time();
        if (isset($this->warned[$operation]) && $now - $this->warned[$operation] < self::WARN_INTERVAL_SECONDS) {
            return;
        }
        $this->warned[$operation] = $now;
        // Safe log shape: operation + exception class only, never payloads.
        $this->logger->warning('Exchange-rate cache operation failed.', [
            'operation' => $operation,
            'exception' => $exception::class,
        ]);
    }
}
