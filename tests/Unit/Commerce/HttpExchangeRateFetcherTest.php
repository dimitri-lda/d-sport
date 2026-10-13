<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\ExchangeRateUnavailable;
use App\Infrastructure\Commerce\HttpExchangeRateFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HttpExchangeRateFetcher::class)]
final class HttpExchangeRateFetcherTest extends TestCase
{
    private const URLS = [
        'myfin' => 'https://myfin.example/eur-byn',
        'walutomat' => 'https://walutomat.example/api/v1/rates',
    ];

    /**
     * @param list<MockResponse>|callable $responses
     */
    private static function fetcher(
        array|callable $responses,
        string $myfinUrl = self::URLS['myfin'],
        string $walutomatUrl = self::URLS['walutomat'],
        string $walutomatToken = 'test-token',
    ): HttpExchangeRateFetcher {
        return new HttpExchangeRateFetcher(
            new MockHttpClient($responses),
            $myfinUrl,
            $walutomatUrl,
            $walutomatToken,
        );
    }

    public function testBynUsesMyfinHtmlFirst(): void
    {
        $requests = 0;
        $fetcher = self::fetcher(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('<html>Курс: 1 EUR = 3,4567 BYN сегодня</html>');
        });

        $rate = $fetcher->fetch('BYN');

        self::assertSame('3.45670000', $rate->rate);
        self::assertSame('myfin', $rate->source);
        self::assertSame(1, $requests, 'A successful Myfin quote must not call NBRB.');
    }

    public function testBynFallsBackToNbrb(): void
    {
        $fetcher = self::fetcher([
            new MockResponse('upstream exploded', ['http_code' => 502]),
            new MockResponse('{"Cur_ID":400,"Cur_OfficialRate":3.21}'),
        ]);

        $rate = $fetcher->fetch('BYN');

        self::assertSame('3.21000000', $rate->rate);
        self::assertSame('nbrb', $rate->source);
    }

    public function testBynAllSourcesFail(): void
    {
        $fetcher = self::fetcher([
            new MockResponse('no rate on this page'),
            new MockResponse('{"Cur_OfficialRate":"n/a"}'),
        ]);

        $this->expectException(ExchangeRateUnavailable::class);
        $fetcher->fetch('BYN');
    }

    public function testPlnUsesWalutomatWithBearerToken(): void
    {
        $authorization = null;
        $fetcher = self::fetcher(static function ($method, $url, $options) use (&$authorization): MockResponse {
            $authorization = $options['normalized_headers']['authorization'] ?? [];

            return new MockResponse('{"rate": 4.2500}');
        });

        $rate = $fetcher->fetch('PLN');

        self::assertSame('4.25000000', $rate->rate);
        self::assertSame('walutomat', $rate->source);
        self::assertNotNull($authorization);
        self::assertStringContainsString(
            'Bearer test-token',
            implode(' ', array_map('strval', $authorization)),
            'The configured bearer token must reach Walutomat.',
        );
    }

    public function testPlnWalutomatAcceptsPriceField(): void
    {
        $fetcher = self::fetcher([new MockResponse('{"price": "4.31"}')]);

        self::assertSame('4.31000000', $fetcher->fetch('PLN')->rate);
    }

    public function testPlnFallsBackToNbpWhenWalutomatUnconfigured(): void
    {
        $fetcher = self::fetcher(
            [new MockResponse('{"rates":[{"no":"001/A/2026","mid":4.2800}]}')],
            walutomatUrl: '',
            walutomatToken: '',
        );

        $rate = $fetcher->fetch('PLN');

        self::assertSame('4.28000000', $rate->rate);
        self::assertSame('nbp', $rate->source);
    }

    public function testPlnWalutomatErrorStillReachesNbp(): void
    {
        $fetcher = self::fetcher([
            new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]),
            new MockResponse('{"rates":[{"mid":4.2900}]}'),
        ]);

        $rate = $fetcher->fetch('PLN');
        self::assertSame('4.29000000', $rate->rate);
        self::assertSame('nbp', $rate->source);
    }

    public function testUnknownCurrencyIsUnavailable(): void
    {
        $this->expectException(ExchangeRateUnavailable::class);
        self::fetcher([])->fetch('USD');
    }

    public function testProviderDecimalsNeverBecomeFloats(): void
    {
        // Nine fractional digits: the token is truncated to 8, not rounded.
        // Myfin is left unconfigured so the single NBRB response is consumed
        // by the source this test targets.
        $fetcher = self::fetcher([new MockResponse('{"Cur_OfficialRate":3.123456789}')], myfinUrl: '');

        self::assertSame('3.12345678', $fetcher->fetch('BYN')->rate);
    }

    public function testCommaDecimalIsNormalized(): void
    {
        $fetcher = self::fetcher([new MockResponse('{"Cur_OfficialRate":"3,4567"}')], myfinUrl: '');

        self::assertSame('3.45670000', $fetcher->fetch('BYN')->rate);
    }

    public function testZeroRateIsSkippedForTheNextSource(): void
    {
        $fetcher = self::fetcher(
            [new MockResponse('{"Cur_OfficialRate":0}')],
            myfinUrl: '',
        );
        // A zero quote is not a rate: with NBRB as the only BYN fallback left
        // failing too, the whole chain is unavailable.
        $this->expectException(ExchangeRateUnavailable::class);
        $fetcher->fetch('BYN');
    }
}
