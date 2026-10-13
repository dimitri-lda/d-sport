<?php

declare(strict_types=1);

namespace App\Tests\Unit\Commerce;

use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\ExchangeRateRefresher;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Application\Commerce\RefreshExchangeRates;
use App\Domain\Commerce\ExchangeRate;
use App\Infrastructure\Commerce\RefreshExchangeRatesHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RefreshExchangeRatesHandler::class)]
final class RefreshExchangeRatesHandlerTest extends TestCase
{
    private static function handler(callable $refresh): RefreshExchangeRatesHandler
    {
        $provider = self::createStub(ExchangeRateProvider::class);
        $provider->method('refresh')->willReturnCallback($refresh);

        return new RefreshExchangeRatesHandler(
            new ExchangeRateRefresher($provider),
            new NullLogger(),
        );
    }

    public function testSuccessfulRefreshDoesNotThrow(): void
    {
        $handler = self::handler(
            static fn (string $currency, \DateTimeImmutable $now): ExchangeRate => new ExchangeRate(
                $currency, '4.28000000', 'walutomat', $now,
            ),
        );

        ($handler)(new RefreshExchangeRates());
        self::addToAssertionCount(1);
    }

    public function testPartialFailureRethrowsForMessengerRetry(): void
    {
        $handler = self::handler(
            static function (string $currency, \DateTimeImmutable $now): ExchangeRate {
                if ('BYN' === $currency) {
                    throw new ExchangeRateUnavailable('all sources down');
                }

                return new ExchangeRate($currency, '4.28000000', 'nbp', $now);
            },
        );

        try {
            ($handler)(new RefreshExchangeRates());
            self::fail('A failed currency must bubble so Messenger retries the message.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('BYN', $exception->getMessage());
        }
    }
}
