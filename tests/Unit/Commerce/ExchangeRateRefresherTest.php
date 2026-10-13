<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\ExchangeRateRefresher;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Domain\Commerce\ExchangeRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeRateRefresher::class)]
final class ExchangeRateRefresherTest extends TestCase
{
    public function testMixedResultsAreReportedPerCurrency(): void
    {
        $provider = $this->createMock(ExchangeRateProvider::class);
        $provider->method('refresh')->willReturnCallback(
            static function (string $currency, \DateTimeImmutable $now): ExchangeRate {
                if ('BYN' === $currency) {
                    throw new ExchangeRateUnavailable('all sources down');
                }

                return new ExchangeRate($currency, '4.28000000', 'walutomat', $now);
            },
        );

        $results = (new ExchangeRateRefresher($provider))
            ->refreshAll(new \DateTimeImmutable('2026-02-01T12:00:00+00:00'));

        self::assertSame(['PLN', 'BYN'], array_keys($results));
        self::assertTrue($results['PLN']['ok']);
        self::assertSame('4.28000000', $results['PLN']['rate']);
        self::assertSame('walutomat', $results['PLN']['source']);
        self::assertFalse($results['BYN']['ok']);
        self::assertSame(ExchangeRateUnavailable::class, $results['BYN']['error']);
        // A failure in one currency must not stop the others.
        self::assertArrayNotHasKey('exception', $results['BYN']);
    }

    public function testSafeResultShapeNeverLeaksMessages(): void
    {
        $provider = $this->createMock(ExchangeRateProvider::class);
        $provider->method('refresh')->willThrowException(
            new \RuntimeException('secret-token rejected with body trace'),
        );

        $results = (new ExchangeRateRefresher($provider))
            ->refreshAll(new \DateTimeImmutable());

        foreach ($results as $result) {
            self::assertFalse($result['ok']);
            self::assertSame(\RuntimeException::class, $result['error']);
            self::assertStringNotContainsString('secret-token', (string) json_encode($result));
        }
    }
}
