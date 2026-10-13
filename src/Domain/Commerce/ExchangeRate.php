<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\DomainException;

/**
 * An exchange-rate quote. The rate is a decimal string parsed and stored
 * canonically with exactly 8 fractional places — never a float — so
 * downstream money math can scale it into integer arithmetic.
 */
final class ExchangeRate
{
    public readonly string $currency;
    public readonly string $rate;
    public readonly string $source;
    public readonly \DateTimeImmutable $quotedAt;

    public function __construct(string $currency, string $rate, string $source, \DateTimeImmutable $quotedAt)
    {
        $this->currency = self::normalizeCurrency($currency);
        $this->rate = self::normalizeRate($rate);
        $this->source = self::normalizeSource($source);
        $this->quotedAt = $quotedAt;
    }

    public function isFresh(\DateTimeImmutable $now, \DateInterval $ttl): bool
    {
        return $this->quotedAt >= $now->sub($ttl);
    }

    private static function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('The currency must be a 3-letter code.');
        }

        return $currency;
    }

    private static function normalizeRate(string $rate): string
    {
        $rate = str_replace(',', '.', trim($rate));
        if (1 !== preg_match('/^\d+(\.\d{1,8})?$/', $rate)) {
            throw new DomainException('The rate must be a positive decimal with at most 8 fractional places.');
        }
        [$integer, $fraction] = array_pad(explode('.', $rate, 2), 2, '');
        $integer = ltrim($integer, '0');
        $fraction = str_pad($fraction, 8, '0');
        if ('' === $integer) {
            $integer = '0';
        }
        if (strlen($integer) > 10) {
            throw new DomainException('The rate exceeds the numeric(18,8) storage range.');
        }
        if ('0' === $integer && 0 === (int) $fraction) {
            throw new DomainException('The rate must be positive.');
        }

        return $integer.'.'.$fraction;
    }

    private static function normalizeSource(string $source): string
    {
        $source = trim($source);
        if ('' === $source || mb_strlen($source) > 40) {
            throw new DomainException('The rate source must be 1-40 characters.');
        }

        return $source;
    }
}
