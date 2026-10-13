<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce;

use App\Application\Commerce\CountryLookup;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Best-effort geo-IP country lookup. Never fails the storefront: every
 * transport or provider problem resolves to null. Positive answers are cached
 * for 30 days, misses for a short negative TTL.
 */
final class GeoIpCountryResolver implements CountryLookup
{
    private const MISS = "\0geoip-miss\0";
    private const POSITIVE_TTL_SECONDS = 2592000; // 30 days
    private const NEGATIVE_TTL_SECONDS = 600;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $geoipUrl,
    ) {
    }

    public function resolve(string $ip): ?string
    {
        if ('' === $ip || \in_array($ip, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        // The cache is disposable: a store outage must never fail the lookup.
        $item = null;
        try {
            $item = $this->cache->getItem('geoip.'.hash('sha256', $ip));
            if ($item->isHit()) {
                $cached = $item->get();

                return self::MISS === $cached ? null : (\is_string($cached) ? $cached : null);
            }
        } catch (\Throwable) {
            $item = null;
        }

        $country = $this->lookup($ip);

        if (null !== $item) {
            try {
                $item->set($country ?? self::MISS);
                $item->expiresAfter(null !== $country ? self::POSITIVE_TTL_SECONDS : self::NEGATIVE_TTL_SECONDS);
                $this->cache->save($item);
            } catch (\Throwable) {
                // Write-through best effort only.
            }
        }

        return $country;
    }

    private function lookup(string $ip): ?string
    {
        try {
            $url = str_replace('{ip}', rawurlencode($ip), $this->geoipUrl);
            $response = $this->httpClient->request('GET', $url, ['timeout' => 2.0]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $data = $response->toArray(false);
            if (true !== ($data['success'] ?? false)) {
                return null;
            }
            $code = $data['country_code'] ?? null;
            if (!\is_string($code) || 1 !== preg_match('/^[A-Za-z]{2}$/', $code)) {
                return null;
            }

            return strtoupper($code);
        } catch (\Throwable) {
            return null;
        }
    }
}
