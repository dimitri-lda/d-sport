<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Application\Catalog\RefreshCatalogCache;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Generation-scoped stale-while-revalidate cache for the storefront.
 *
 * Entries live under prefix/group/generation/hash keys; rotating a group's
 * generation orphans every entry of that group at once. Each entry is an
 * envelope {value, fresh_until, expires_at}: fresh hits return directly, stale hits
 * return immediately and dispatch a debounced async refresh, misses fill
 * under a Symfony lock. Every pool/lock/bus failure degrades to a direct
 * loader call with one safe warning — the catalog stays available with
 * Redis down. The service holds no mutable per-request state because
 * workers are long-lived.
 */
final class StorefrontCache
{
    private const GROUPS = ['tree', 'list', 'card'];
    /**
     * Part of every entry key: bump when a cached payload's shape changes so
     * entries written by an older release are orphaned at deploy time rather
     * than read back missing the new fields.
     */
    private const PAYLOAD_VERSION = 2;
    private const GENERATION_KEY = 'generation';
    private const REFRESH_DEBOUNCE_SECONDS = 60;
    private const WARN_INTERVAL_SECONDS = 300;
    private const LOCK_TTL_SECONDS = 10.0;
    private const LOCK_WAIT_MS = 500;

    /** @var array<string, int> */
    private array $warned = [];

    /**
     * @param array{tree: CacheItemPoolInterface, list: CacheItemPoolInterface, card: CacheItemPoolInterface}                      $pools
     * @param array{tree: array{fresh: int, total: int}, list: array{fresh: int, total: int}, card: array{fresh: int, total: int}} $ttl
     */
    public function __construct(
        private readonly array $pools,
        private readonly array $ttl,
        private readonly bool $enabled,
        private readonly string $prefix,
        private readonly int $generationTtl,
        private readonly LockFactory $locks,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * SWR read-through. The loader's exceptions always propagate — the
     * caller sees the real error, never a cache-shaped one.
     */
    public function remember(
        string $group,
        string $logicalKey,
        \Closure $loader,
        ?RefreshCatalogCache $refresh = null,
    ): mixed {
        $pool = $this->pool($group);
        if (!$this->enabled) {
            return $loader();
        }
        $key = $this->key($pool, $group, $logicalKey);
        if (null === $key) {
            return $loader();
        }

        $entry = $this->read($pool, $key);
        if (null !== $entry) {
            if ($entry['fresh_until'] > $this->now()) {
                return $entry['value'];
            }
            $this->deferRefresh($pool, $key, $refresh);

            return $entry['value'];
        }

        return $this->fill($pool, $group, $key, $loader);
    }

    /** Write-through used by async refreshes and the warmer. */
    public function put(string $group, string $logicalKey, mixed $value): void
    {
        $pool = $this->pool($group);
        if (!$this->enabled) {
            return;
        }
        $key = $this->key($pool, $group, $logicalKey);
        if (null !== $key) {
            $this->write($pool, $group, $key, $value);
        }
    }

    /** Bump the generation of each named group; orphaned entries expire naturally. */
    public function rotate(string ...$groups): void
    {
        foreach ($groups as $group) {
            $pool = $this->pool($group);
            try {
                $pool->deleteItem(self::GENERATION_KEY);
            } catch (\Throwable $exception) {
                $this->warn('rotate', $exception);
            }
        }
    }

    public function flush(): void
    {
        $this->rotate(...self::GROUPS);
    }

    private function pool(string $group): CacheItemPoolInterface
    {
        $pool = $this->pools[$group] ?? null;
        if (!$pool instanceof CacheItemPoolInterface) {
            throw new \InvalidArgumentException('Unknown storefront cache group "'.$group.'".');
        }

        return $pool;
    }

    /**
     * Full cache key, or null when the generation store is unavailable —
     * a lost token must produce a loader call, never data from an old
     * generation.
     */
    private function key(CacheItemPoolInterface $pool, string $group, string $logicalKey): ?string
    {
        $generation = $this->generation($pool, $group);
        if (null === $generation) {
            return null;
        }

        return implode('.', [
            $this->prefix,
            $group,
            'v'.self::PAYLOAD_VERSION,
            $generation,
            hash('sha256', $logicalKey),
        ]);
    }

    /**
     * First-request race: a missing generation is initialized under the
     * shared lock so concurrent misses share one token instead of each
     * inventing (and orphaning) their own. Without the lock — busy or
     * broken — we use whatever was published or fall back to a direct
     * load; a competing generation is never saved unlocked.
     */
    private function generation(CacheItemPoolInterface $pool, string $group): ?string
    {
        $generation = $this->readGeneration($pool);
        if (null !== $generation) {
            return $generation;
        }

        $lock = null;
        try {
            $lock = $this->locks->createLock(
                'storefront.'.$this->prefix.'.'.$group.'.'.self::GENERATION_KEY,
                self::LOCK_TTL_SECONDS,
            );
        } catch (\Throwable $exception) {
            $this->warn('lock', $exception);
        }
        if (null === $lock) {
            return null;
        }
        try {
            $acquired = $lock->acquire();
        } catch (\Throwable $exception) {
            $this->warn('lock', $exception);
            $acquired = false;
        }
        if (!$acquired) {
            return $this->readGeneration($pool);
        }
        try {
            // Double-check under lock: the winner may have published already.
            $generation = $this->readGeneration($pool);
            if (null !== $generation) {
                return $generation;
            }
            $generation = bin2hex(random_bytes(4));
            try {
                $item = $pool->getItem(self::GENERATION_KEY);
                $item->set($generation)->expiresAfter($this->generationTtl);
                $pool->save($item);
            } catch (\Throwable $exception) {
                $this->warn('generation', $exception);

                return null;
            }

            return $generation;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $exception) {
                $this->warn('lock', $exception);
            }
        }
    }

    private function readGeneration(CacheItemPoolInterface $pool): ?string
    {
        try {
            $item = $pool->getItem(self::GENERATION_KEY);
            $generation = $item->isHit() ? $item->get() : null;
        } catch (\Throwable $exception) {
            $this->warn('generation', $exception);

            return null;
        }

        return \is_string($generation) && '' !== $generation ? $generation : null;
    }

    /** @return array{value: mixed, fresh_until: int, expires_at: int}|null */
    private function read(CacheItemPoolInterface $pool, string $key): ?array
    {
        try {
            $item = $pool->getItem($key);
            if (!$item->isHit()) {
                return null;
            }
            $entry = $item->get();
            if (
                !\is_array($entry)
                || !\array_key_exists('value', $entry)
                || !\is_int($entry['fresh_until'] ?? null)
                || !\is_int($entry['expires_at'] ?? null)
                || $entry['expires_at'] <= $this->now()
            ) {
                return null;
            }

            return $entry;
        } catch (\Throwable $exception) {
            $this->warn('read', $exception);

            return null;
        }
    }

    private function write(CacheItemPoolInterface $pool, string $group, string $key, mixed $value): void
    {
        $ttl = $this->ttl[$group];
        try {
            $now = $this->now();
            $item = $pool->getItem($key);
            $item->set([
                'value' => $value,
                'fresh_until' => $now + $ttl['fresh'],
                'expires_at' => $now + $ttl['total'],
            ]);
            $item->expiresAfter($ttl['total']);
            $pool->save($item);
        } catch (\Throwable $exception) {
            $this->warn('write', $exception);
        }
    }

    private function fill(CacheItemPoolInterface $pool, string $group, string $key, \Closure $loader): mixed
    {
        $lock = null;
        try {
            $lock = $this->locks->createLock('storefront.'.$key, self::LOCK_TTL_SECONDS);
        } catch (\Throwable $exception) {
            $this->warn('lock', $exception);
        }

        if (null !== $lock) {
            try {
                $acquired = $lock->acquire();
            } catch (\Throwable $exception) {
                $this->warn('lock', $exception);
                $acquired = false;
            }
            if ($acquired) {
                try {
                    // Another worker may have filled while we waited.
                    $entry = $this->read($pool, $key);
                    if (null !== $entry && $entry['fresh_until'] > $this->now()) {
                        return $entry['value'];
                    }
                    $value = $loader();
                    $this->write($pool, $group, $key, $value);

                    return $value;
                } finally {
                    try {
                        $lock->release();
                    } catch (\Throwable $exception) {
                        $this->warn('lock', $exception);
                    }
                }
            }
            // The lock is busy: poll briefly for the other fill to publish.
            for ($waited = 0; $waited < self::LOCK_WAIT_MS; $waited += 50) {
                usleep(50_000);
                $entry = $this->read($pool, $key);
                if (null !== $entry) {
                    return $entry['value'];
                }
            }
        }

        $value = $loader();
        $this->write($pool, $group, $key, $value);

        return $value;
    }

    private function deferRefresh(CacheItemPoolInterface $pool, string $key, ?RefreshCatalogCache $refresh): void
    {
        if (null === $refresh) {
            return;
        }
        try {
            $marker = $pool->getItem($key.'.refreshing');
            if ($marker->isHit()) {
                return;
            }
            $marker->set(true)->expiresAfter(self::REFRESH_DEBOUNCE_SECONDS);
            $pool->save($marker);
        } catch (\Throwable $exception) {
            $this->warn('debounce', $exception);
        }
        try {
            $this->bus->dispatch($refresh);
        } catch (\Throwable $exception) {
            $this->warn('dispatch', $exception);
        }
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * At most one warning per operation per WARN_INTERVAL_SECONDS — a
     * permanently suppressed warning would hide an ongoing outage from a
     * long-running worker, while an unthrottled one would flood the log.
     * The map stays worker-safe: the operation names are code constants.
     */
    private function warn(string $operation, \Throwable $exception): void
    {
        $now = $this->now();
        $last = $this->warned[$operation] ?? null;
        if (null !== $last && $now - $last < self::WARN_INTERVAL_SECONDS) {
            return;
        }
        $this->warned[$operation] = $now;
        $this->logger->warning('Storefront cache operation failed.', [
            'operation' => $operation,
            'exception' => $exception::class,
        ]);
    }
}
