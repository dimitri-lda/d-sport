<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\CountryCatalog;
use App\Application\Commerce\CountryLookup;
use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Application\Commerce\MarketContextResolver;
use App\Domain\Commerce\ExchangeRate;
use App\Presentation\Http\ApiProblem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

#[CoversClass(MarketContextResolver::class)]
final class MarketContextResolverTest extends TestCase
{
    private CountryCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new CountryCatalog(
            markets: [
                'BY' => ['code' => 'belarus', 'currency' => 'BYN', 'locale' => 'ru', 'vat_basis_points' => 2000],
                'PL' => ['code' => 'poland', 'currency' => 'PLN', 'locale' => 'pl', 'vat_basis_points' => 2300],
            ],
            euVatBasisPoints: ['DE' => 1900],
            countryNames: ['DE' => ['en' => 'Germany']],
            locales: ['en', 'ru', 'pl'],
            defaultCountry: 'DE',
            fallbackMarket: 'eu_global',
        );
    }

    private function resolver(CountryLookup $geoIp, ExchangeRateProvider $rates): MarketContextResolver
    {
        return new MarketContextResolver($this->catalog, $geoIp, $rates);
    }

    private static function rates(string $currency = 'EUR', string $rate = '1.00000000'): ExchangeRateProvider
    {
        $provider = self::createStub(ExchangeRateProvider::class);
        $provider->method('rate')->willReturnCallback(
            static fn (string $c): ExchangeRate => $c === $currency
                ? new ExchangeRate($c, $rate, 'test', new \DateTimeImmutable())
                : throw new ExchangeRateUnavailable('No quote for '.$c.'.'),
        );

        return $provider;
    }

    private static function requestWithSession(): Request
    {
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    public function testSessionPreferenceWinsOverGeoIp(): void
    {
        $geoIp = self::createStub(CountryLookup::class);
        $geoIp->method('resolve')->willReturn('PL');
        $request = self::requestWithSession();
        $request->getSession()->set(MarketContextResolver::SESSION_KEY, ['country' => 'BY', 'locale' => 'ru']);

        $context = $this->resolver($geoIp, self::rates('BYN', '3.45000000'))->resolve($request);

        self::assertSame('BY', $context->country);
        self::assertSame('belarus', $context->market);
        self::assertSame('3.45000000', $context->exchangeRate);
    }

    public function testGeoIpCountryUsedAndStoredWhenNoPreference(): void
    {
        $geoIp = self::createStub(CountryLookup::class);
        $geoIp->method('resolve')->willReturn('PL');
        $request = self::requestWithSession();

        $context = $this->resolver($geoIp, self::rates('PLN', '4.28000000'))->resolve($request);

        self::assertSame('PL', $context->country);
        self::assertSame('pl', $context->locale);
        self::assertSame(
            ['country' => 'PL', 'locale' => 'pl'],
            $request->getSession()->get(MarketContextResolver::SESSION_KEY),
        );
    }

    public function testGeoIpMissFallsBackToDefaultCountry(): void
    {
        $geoIp = self::createStub(CountryLookup::class);
        $geoIp->method('resolve')->willReturn(null);
        $request = self::requestWithSession();

        $context = $this->resolver($geoIp, self::rates())->resolve($request);

        self::assertSame('DE', $context->country);
        self::assertSame('EUR', $context->currency);
        self::assertSame(['country' => 'DE', 'locale' => 'en'], $request->getSession()->get('market'));
    }

    public function testMissingNonEurRateYields503(): void
    {
        $geoIp = self::createStub(CountryLookup::class);
        $geoIp->method('resolve')->willReturn('BY');
        $request = self::requestWithSession();

        try {
            $this->resolver($geoIp, self::rates())->resolve($request);
            self::fail('Expected ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame(503, $problem->status);
            self::assertSame('exchange_rate_unavailable', $problem->errorCode);
        }
    }

    public function testApplyPreferencesPersistsSession(): void
    {
        $geoIp = self::createStub(CountryLookup::class);
        $request = self::requestWithSession();

        $context = $this->resolver($geoIp, self::rates('PLN', '4.28000000'))
            ->applyPreferences($request->getSession(), 'PL', 'en');

        self::assertSame('PL', $context->country);
        self::assertSame('en', $context->locale);
        self::assertSame(['country' => 'PL', 'locale' => 'en'], $request->getSession()->get('market'));
    }
}
