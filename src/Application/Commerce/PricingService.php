<?php

declare(strict_types=1);

namespace App\Application\Commerce;

use App\Domain\Commerce\MarketContext;
use App\Domain\DomainException;

/**
 * Integer-money pricing for a resolved market. Exchange rates are decimal
 * strings parsed to an 8-decimal scaled integer; all arithmetic is integer
 * math with half-up rounding — no floats anywhere.
 */
final class PricingService
{
    private const RATE_SCALE = 100000000; // 1e8
    private const BPS_DENOMINATOR = 10000;

    public function __construct(
        private readonly int $standardNetCents,
        private readonly int $expressNetCents,
        private readonly int $freeShippingGrossEurCents,
    ) {
        foreach ([$standardNetCents, $expressNetCents, $freeShippingGrossEurCents] as $value) {
            if ($value < 0) {
                throw new DomainException('Delivery pricing configuration must not be negative.');
            }
        }
    }

    /**
     * Gross price for display. Catalog, cart and order lines share one
     * formula: convert the net amount into the market currency first (a
     * whole number of cents), then apply VAT — so the listed price is
     * always the charged price.
     */
    public function grossCents(int $netEuroCents, MarketContext $market): int
    {
        return $this->grossFromConvertedNetCents(
            $this->convertedNetCents($netEuroCents, $market),
            $market,
        );
    }

    /**
     * Rate-converts a stored net EUR amount into the market currency,
     * rounded half-up at the unit level. Used by cart/checkout where VAT is
     * applied to the already-converted net amount (legacy semantics).
     */
    public function convertedNetCents(int $netEuroCents, MarketContext $market): int
    {
        if ($netEuroCents < 0) {
            throw new DomainException('Net price must not be negative.');
        }
        $rateScaled = self::scaleRate($market->exchangeRate);

        return self::roundHalfUp(self::checkedMultiply($netEuroCents, $rateScaled), self::RATE_SCALE);
    }

    /**
     * Applies market VAT to an already rate-converted net amount, rounded
     * half-up. Legacy cart/order pricing: convert net first, then tax.
     */
    public function grossFromConvertedNetCents(int $convertedNetCents, MarketContext $market): int
    {
        if ($convertedNetCents < 0) {
            throw new DomainException('Net price must not be negative.');
        }
        if ($market->vatBasisPoints < 0 || $market->vatBasisPoints > self::BPS_DENOMINATOR) {
            throw new DomainException('VAT basis points must be between 0 and 10000.');
        }

        return self::roundHalfUp(
            self::checkedMultiply($convertedNetCents, self::BPS_DENOMINATOR + $market->vatBasisPoints),
            self::BPS_DENOMINATOR,
        );
    }

    /** @return array{standard: int, express: int, free_standard_threshold: int} */
    public function deliveryQuotes(int $cartGrossCents, MarketContext $market): array
    {
        if ($cartGrossCents < 0) {
            throw new DomainException('Cart gross total must not be negative.');
        }
        $rateScaled = self::scaleRate($market->exchangeRate);
        $threshold = self::roundHalfUp(
            self::checkedMultiply($this->freeShippingGrossEurCents, $rateScaled),
            self::RATE_SCALE,
        );

        return [
            'standard' => $cartGrossCents >= $threshold ? 0 : $this->grossFromConvertedNetCents(
                $this->convertedNetCents($this->standardNetCents, $market),
                $market,
            ),
            'express' => $this->grossFromConvertedNetCents(
                $this->convertedNetCents($this->expressNetCents, $market),
                $market,
            ),
            'free_standard_threshold' => $threshold,
        ];
    }

    /**
     * Converted net delivery fee for each method at a given gross subtotal;
     * standard is 0 once the free-shipping threshold is reached.
     *
     * @return array{standard: int, express: int}
     */
    public function deliveryNetQuotes(int $cartGrossCents, MarketContext $market): array
    {
        $quotes = $this->deliveryQuotes($cartGrossCents, $market);

        return [
            'standard' => 0 === $quotes['standard']
                ? 0
                : $this->convertedNetCents($this->standardNetCents, $market),
            'express' => $this->convertedNetCents($this->expressNetCents, $market),
        ];
    }

    /**
     * VAT portion of each delivery quote at a given gross subtotal;
     * standard is 0 once shipping is free.
     *
     * @return array{standard: int, express: int}
     */
    public function deliveryTaxQuotes(int $cartGrossCents, MarketContext $market): array
    {
        $quotes = $this->deliveryQuotes($cartGrossCents, $market);
        $net = $this->deliveryNetQuotes($cartGrossCents, $market);

        return [
            'standard' => $quotes['standard'] - $net['standard'],
            'express' => $quotes['express'] - $net['express'],
        ];
    }

    /**
     * Converted net fee for a delivery method at a given gross subtotal;
     * 0 when standard shipping is free at that subtotal.
     */
    public function deliveryNetCents(string $method, int $cartGrossCents, MarketContext $market): int
    {
        return $this->deliveryNetQuotes($cartGrossCents, $market)['standard' === $method ? 'standard' : 'express'];
    }

    /** Parses a positive decimal string into an 8-decimal scaled integer. */
    private static function scaleRate(string $rate): int
    {
        if (1 !== preg_match('/^(\d+)(?:\.(\d{1,8}))?$/', $rate, $m)) {
            throw new DomainException(\sprintf('Malformed exchange rate "%s".', $rate));
        }
        $fraction = str_pad($m[2] ?? '', 8, '0');
        $scaled = ((int) $m[1]) * self::RATE_SCALE + (int) $fraction;
        if (0 === $scaled) {
            throw new DomainException('Exchange rate must be greater than zero.');
        }

        return $scaled;
    }

    /** Non-negative integer multiplication that refuses silent overflow. */
    private static function checkedMultiply(int $a, int $b): int
    {
        if ($a < 0 || $b < 0 || (0 !== $b && $a > intdiv(PHP_INT_MAX, $b))) {
            throw new DomainException('Price is too large to calculate safely.');
        }

        return $a * $b;
    }

    /** Half-up rounding of a non-negative rational num/den via integer math. */
    private static function roundHalfUp(int $num, int $den): int
    {
        $quotient = intdiv($num, $den);
        $remainder = $num % $den;

        return $quotient + (2 * $remainder >= $den ? 1 : 0);
    }
}
