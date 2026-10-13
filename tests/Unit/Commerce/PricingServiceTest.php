<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\PricingService;
use App\Domain\Commerce\MarketContext;
use App\Domain\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PricingService::class)]
final class PricingServiceTest extends TestCase
{
    private PricingService $pricing;

    protected function setUp(): void
    {
        $this->pricing = new PricingService(
            standardNetCents: 590,
            expressNetCents: 1290,
            freeShippingGrossEurCents: 5000,
        );
    }

    private static function market(string $rate = '1.00000000', int $vat = 1900): MarketContext
    {
        return new MarketContext('DE', 'eu_global', 'EUR', 'en', $vat, $rate);
    }

    public function testGrossAppliesRateAndVat(): void
    {
        self::assertSame(11900, $this->pricing->grossCents(10000, self::market('1.00000000', 1900)));
        // 1000 * 4.28 * 1.23 = 5264.4 -> 5264
        self::assertSame(5264, $this->pricing->grossCents(1000, self::market('4.28000000', 2300)));
    }

    public function testHalfUpRounding(): void
    {
        // Exactly x.5 must round up.
        self::assertSame(2, $this->pricing->grossCents(1, self::market('1.00000000', 5000)));
        // Just below half rounds down.
        self::assertSame(1, $this->pricing->grossCents(1, self::market('1.00000000', 4999)));
        // 2500 * 1.23456789 = 3086.419725 -> net 3086, then 3086 * 1.20 = 3703.2 -> 3703
        self::assertSame(3703, $this->pricing->grossCents(2500, self::market('1.23456789', 2000)));
    }

    public function testGrossIsAlwaysConvertThenTax(): void
    {
        // The invariant that was once broken: the single-step formula used
        // to disagree with the cart's convert-then-tax path on whole-cent
        // boundaries. grossCents must be exactly that composition.
        foreach ([['4.28000000', 2300], ['0.95120000', 810]] as [$rate, $vat]) {
            $market = self::market($rate, $vat);
            for ($net = 100; $net <= 20000; ++$net) {
                self::assertSame(
                    $this->pricing->grossFromConvertedNetCents(
                        $this->pricing->convertedNetCents($net, $market),
                        $market,
                    ),
                    $this->pricing->grossCents($net, $market),
                    \sprintf('net=%d rate=%s vat=%d', $net, $rate, $vat),
                );
            }
        }
    }

    public function testRejectsNegativeAmountsAndMalformedRates(): void
    {
        $this->expectException(DomainException::class);
        $this->pricing->grossCents(-1, self::market());
    }

    public function testMalformedRateRejected(): void
    {
        foreach (['abc', '', '1.234567891', '-1.5', '1,5'] as $rate) {
            try {
                $this->pricing->grossCents(100, self::market($rate));
                self::fail(\sprintf('Rate "%s" should have been rejected.', $rate));
            } catch (DomainException) {
            }
        }
        self::assertSame(190, $this->pricing->grossCents(100, self::market('1', 9000)));
    }

    public function testDeliveryQuotesBelowAndAboveThreshold(): void
    {
        $quotes = $this->pricing->deliveryQuotes(4999, self::market());
        self::assertSame(702, $quotes['standard']);   // 590 * 1.19 = 702.1 -> 702
        self::assertSame(1535, $quotes['express']);   // 1290 * 1.19 = 1535.1 -> 1535
        self::assertSame(5000, $quotes['free_standard_threshold']);

        $quotes = $this->pricing->deliveryQuotes(5000, self::market());
        self::assertSame(0, $quotes['standard']);
        self::assertSame(1535, $quotes['express']); // express is never free
    }

    public function testZeroRateIsRejected(): void
    {
        foreach (['0', '0.00000000', '0.0'] as $rate) {
            try {
                $this->pricing->grossCents(100, self::market($rate));
                self::fail(\sprintf('Rate "%s" should have been rejected.', $rate));
            } catch (DomainException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testVatBasisPointsOutsideBoundsRejected(): void
    {
        foreach ([-1, 10001] as $vat) {
            try {
                $this->pricing->grossCents(100, self::market('1.00000000', $vat));
                self::fail(\sprintf('VAT %d should have been rejected.', $vat));
            } catch (DomainException) {
            }
        }
        // Bounds are inclusive: 0% and 100% are legal.
        self::assertSame(100, $this->pricing->grossCents(100, self::market('1.00000000', 0)));
        self::assertSame(200, $this->pricing->grossCents(100, self::market('1.00000000', 10000)));
    }

    public function testOversizedAmountsThrowDomainExceptionInsteadOfOverflowing(): void
    {
        try {
            $this->pricing->grossCents(PHP_INT_MAX, self::market('4.28000000', 2300));
            self::fail('Oversized multiplication should have been rejected.');
        } catch (DomainException $exception) {
            self::assertSame('Price is too large to calculate safely.', $exception->getMessage());
        }
        try {
            $this->pricing->grossCents(PHP_INT_MAX, self::market());
            self::fail('Oversized multiplication should have been rejected.');
        } catch (DomainException) {
        }
    }

    public function testNegativeConstructorConfigRejected(): void
    {
        try {
            new PricingService(-1, 1290, 5000);
            self::fail('Negative standard rate should have been rejected.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        try {
            new PricingService(590, 1290, -1);
            self::fail('Negative threshold should have been rejected.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }

    public function testDeliveryThresholdConvertsByRateOnly(): void
    {
        // PLN at 4.28: threshold is 5000 EUR * 4.28 = 21400 PLN cents, no VAT.
        $quotes = $this->pricing->deliveryQuotes(21399, self::market('4.28000000', 2300));
        self::assertSame(21400, $quotes['free_standard_threshold']);
        self::assertNotSame(0, $quotes['standard']);
        $quotes = $this->pricing->deliveryQuotes(21400, self::market('4.28000000', 2300));
        self::assertSame(0, $quotes['standard']);
    }

    public function testDeliveryQuotesUseLegacyTwoStepRounding(): void
    {
        // Rate 1.00067800: 590 * 1.000678 = 590.40002 -> converted net 590.
        // Two-step: VAT on 590 -> 702.1 -> 702.
        // One-step: 590.40002 * 1.19 = 702.576 -> 703. The results must differ.
        $market = self::market('1.00067800', 1900);

        $quotes = $this->pricing->deliveryQuotes(1, $market);
        self::assertSame(702, $quotes['standard'], 'Standard must round net first, then apply VAT.');
        self::assertSame(1536, $quotes['express']); // 1290.87462 -> 1291 net -> *1.19 = 1536.29
        self::assertSame(590, $this->pricing->deliveryNetQuotes(1, $market)['standard']);
        self::assertSame(1291, $this->pricing->deliveryNetQuotes(1, $market)['express']);
        self::assertSame(112, $this->pricing->deliveryTaxQuotes(1, $market)['standard']);
        self::assertSame(245, $this->pricing->deliveryTaxQuotes(1, $market)['express']);
    }

    public function testDeliveryNetAndTaxQuotesAreZeroWhenStandardIsFree(): void
    {
        $market = self::market();

        self::assertSame(0, $this->pricing->deliveryNetQuotes(5000, $market)['standard']);
        self::assertSame(0, $this->pricing->deliveryTaxQuotes(5000, $market)['standard']);
        self::assertSame(1290, $this->pricing->deliveryNetQuotes(5000, $market)['express']);
        self::assertSame(245, $this->pricing->deliveryTaxQuotes(5000, $market)['express']);
    }
}
