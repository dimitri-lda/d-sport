<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\CountryCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountryCatalog::class)]
final class CountryCatalogTest extends TestCase
{
    private CountryCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new CountryCatalog(
            markets: [
                'BY' => ['code' => 'belarus', 'currency' => 'BYN', 'locale' => 'ru', 'vat_basis_points' => 2000],
                'PL' => ['code' => 'poland', 'currency' => 'PLN', 'locale' => 'pl', 'vat_basis_points' => 2300],
            ],
            euVatBasisPoints: ['DE' => 1900, 'FR' => 2000, 'PL' => 2300],
            countryNames: [
                'BY' => ['en' => 'Belarus', 'ru' => 'Беларусь'],
                'PL' => ['en' => 'Poland', 'pl' => 'Polska'],
                'DE' => ['en' => 'Germany', 'pl' => 'Niemcy'],
                'FR' => ['en' => 'France'],
            ],
            locales: ['en', 'ru', 'pl'],
            defaultCountry: 'DE',
            fallbackMarket: 'eu_global',
        );
    }

    public function testDedicatedMarketsResolve(): void
    {
        self::assertSame(
            ['country' => 'BY', 'market' => 'belarus', 'currency' => 'BYN', 'locale' => 'ru', 'vat_basis_points' => 2000],
            $this->catalog->resolve('by', null),
        );
        self::assertSame(
            ['country' => 'PL', 'market' => 'poland', 'currency' => 'PLN', 'locale' => 'pl', 'vat_basis_points' => 2300],
            $this->catalog->resolve('PL', null),
        );
    }

    public function testEuCountryResolvesToGlobalMarketWithCountryVat(): void
    {
        self::assertSame(
            ['country' => 'DE', 'market' => 'eu_global', 'currency' => 'EUR', 'locale' => 'en', 'vat_basis_points' => 1900],
            $this->catalog->resolve('DE', null),
        );
        self::assertSame(
            ['country' => 'FR', 'market' => 'eu_global', 'currency' => 'EUR', 'locale' => 'en', 'vat_basis_points' => 2000],
            $this->catalog->resolve('fr', null),
        );
    }

    public function testExplicitLocaleWinsOverMarketLocale(): void
    {
        $resolved = $this->catalog->resolve('DE', 'ru');
        self::assertSame('ru', $resolved['locale']);
        $resolved = $this->catalog->resolve('BY', 'en');
        self::assertSame('en', $resolved['locale']);
    }

    public function testUnsupportedLocaleFallsBackToMarketLocale(): void
    {
        self::assertSame('en', $this->catalog->resolve('DE', 'xx')['locale']);
        self::assertSame('ru', $this->catalog->resolve('BY', 'xx')['locale']);
    }

    public function testUnknownCountryFallsBackToDefault(): void
    {
        self::assertSame(
            // Unknown country falls back to the default; a valid requested locale is kept.
            ['country' => 'DE', 'market' => 'eu_global', 'currency' => 'EUR', 'locale' => 'ru', 'vat_basis_points' => 1900],
            $this->catalog->resolve('XX', 'ru'),
        );
    }

    public function testIsSupported(): void
    {
        self::assertTrue($this->catalog->isSupported('by'));
        self::assertTrue($this->catalog->isSupported('FR'));
        self::assertFalse($this->catalog->isSupported('XX'));
        self::assertTrue($this->catalog->isSupportedLocale('pl'));
        self::assertFalse($this->catalog->isSupportedLocale('de'));
    }

    public function testCountriesListLocalizesNames(): void
    {
        $names = array_column($this->catalog->countries('pl'), 'name', 'code');
        self::assertSame('Polska', $names['PL']);
        self::assertSame('Niemcy', $names['DE']);
        self::assertSame('Беларусь', array_column($this->catalog->countries('ru'), 'name', 'code')['BY']);
        // Missing localization falls back to English.
        self::assertSame('France', array_column($this->catalog->countries('ru'), 'name', 'code')['FR']);
    }
}
