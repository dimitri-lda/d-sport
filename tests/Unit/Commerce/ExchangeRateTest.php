<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Domain\Commerce\ExchangeRate;
use App\Domain\DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeRate::class)]
final class ExchangeRateTest extends TestCase
{
    public function testFreshnessBoundary(): void
    {
        $now = new \DateTimeImmutable('2026-01-15 12:00:00+00');
        $ttl = new \DateInterval('PT6H');

        $fresh = new ExchangeRate('PLN', '4.28', 'test', $now->sub(new \DateInterval('PT5H')));
        self::assertTrue($fresh->isFresh($now, $ttl));

        $boundary = new ExchangeRate('PLN', '4.28', 'test', $now->sub($ttl));
        self::assertTrue($boundary->isFresh($now, $ttl));

        $stale = new ExchangeRate('PLN', '4.28', 'test', $now->sub(new \DateInterval('PT6H1S')));
        self::assertFalse($stale->isFresh($now, $ttl));
    }

    public function testRateIsCanonicalizedToEightDecimalsWithoutFloats(): void
    {
        $now = new \DateTimeImmutable('2026-01-15 12:00:00+00');

        self::assertSame('4.28000000', (new ExchangeRate('PLN', '4.28', 'test', $now))->rate);
        self::assertSame('1.00000000', (new ExchangeRate('EUR', '1', 'base', $now))->rate);
        self::assertSame('4.12345678', (new ExchangeRate('PLN', '4.12345678', 'test', $now))->rate);
        self::assertSame('12.00000000', (new ExchangeRate('PLN', '12.000', 'test', $now))->rate);
        // Comma decimals are normalized, lowercase currencies canonicalized.
        self::assertSame('3.45000000', (new ExchangeRate('byn', '3,45', 'test', $now))->rate);
        self::assertSame('BYN', (new ExchangeRate('byn', '3.45', 'test', $now))->currency);
    }

    /** @return iterable<string, list<string>> */
    public static function invalidRates(): iterable
    {
        yield 'not numeric' => ['abc'];
        yield 'negative' => ['-1.5'];
        yield 'zero' => ['0'];
        yield 'zero decimal' => ['0.00000000'];
        yield 'nine decimals' => ['4.123456789'];
        yield 'empty' => [''];
        yield 'scientific notation' => ['1e-3'];
        yield 'integer part overflow' => ['1234567890123.45'];
        yield 'eleven integer digits' => ['12345678901.5'];
    }

    public function testIntegerPartBoundariesMatchNumeric188(): void
    {
        $now = new \DateTimeImmutable();
        // NUMERIC(18,8): at most 10 integer digits.
        self::assertSame(
            '1234567890.00000000',
            (new ExchangeRate('PLN', '1234567890', 'test', $now))->rate,
        );
        try {
            new ExchangeRate('PLN', '12345678901', 'test', $now);
            self::fail('An 11-digit integer part must be rejected.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('numeric(18,8)', $exception->getMessage());
        }
    }

    #[DataProvider('invalidRates')]
    public function testInvalidRateIsRejected(string $rate): void
    {
        $this->expectException(DomainException::class);
        new ExchangeRate('PLN', $rate, 'test', new \DateTimeImmutable());
    }

    /** @return iterable<string, list<string>> */
    public static function invalidCurrencies(): iterable
    {
        yield 'two letters' => ['EU'];
        yield 'digits' => ['E1R'];
        yield 'four letters' => ['EURO'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidCurrencies')]
    public function testInvalidCurrencyIsRejected(string $currency): void
    {
        $this->expectException(DomainException::class);
        new ExchangeRate($currency, '4.28', 'test', new \DateTimeImmutable());
    }

    public function testInvalidSourceIsRejected(): void
    {
        $now = new \DateTimeImmutable();
        try {
            new ExchangeRate('PLN', '4.28', '', $now);
            self::fail('Empty source must be rejected.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
        try {
            new ExchangeRate('PLN', '4.28', str_repeat('x', 41), $now);
            self::fail('An overlong source must be rejected.');
        } catch (DomainException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }
    }
}
