<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Infrastructure\Commerce\GeoIpCountryResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(GeoIpCountryResolver::class)]
final class GeoIpCountryResolverTest extends TestCase
{
    private const URL = 'https://geoip.test/{ip}';

    private static function provider(string $country): MockHttpClient
    {
        return new MockHttpClient(
            new MockResponse(json_encode(['success' => true, 'country_code' => $country], \JSON_THROW_ON_ERROR)),
        );
    }

    public function testCacheReadFailureStillPerformsProviderLookup(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('Redis is down.'));

        $resolver = new GeoIpCountryResolver(self::provider('pl'), $cache, self::URL);

        self::assertSame('PL', $resolver->resolve('203.0.113.10'));
    }

    public function testCacheSaveFailureStillReturnsLookupResult(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('set')->willReturnSelf();
        $item->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);
        $cache->method('save')->willThrowException(new \RuntimeException('Redis is down.'));

        $resolver = new GeoIpCountryResolver(self::provider('de'), $cache, self::URL);

        self::assertSame('DE', $resolver->resolve('203.0.113.11'));
    }

    public function testPositiveAnswerIsCached(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn('BY');

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);

        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('A cache hit must not call the provider.');
        });
        $resolver = new GeoIpCountryResolver($http, $cache, self::URL);

        self::assertSame('BY', $resolver->resolve('203.0.113.12'));
    }

    public function testCachedNegativeAnswerSkipsProvider(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn("\0geoip-miss\0");

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($item);

        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('A cached miss must not call the provider.');
        });
        $resolver = new GeoIpCountryResolver($http, $cache, self::URL);

        self::assertNull($resolver->resolve('203.0.113.13'));
    }

    public function testProviderFailureResolvesNull(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('No cache.'));

        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse('upstream exploded', ['http_code' => 502]);
        });
        $resolver = new GeoIpCountryResolver($http, $cache, self::URL);

        self::assertNull($resolver->resolve('203.0.113.14'));
    }

    public function testLocalAddressesNeverHitCacheOrProvider(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::never())->method('getItem');

        $http = new MockHttpClient(static function (): never {
            throw new \LogicException('Local IPs must not call the provider.');
        });
        $resolver = new GeoIpCountryResolver($http, $cache, self::URL);

        self::assertNull($resolver->resolve('127.0.0.1'));
        self::assertNull($resolver->resolve(''));
    }
}
