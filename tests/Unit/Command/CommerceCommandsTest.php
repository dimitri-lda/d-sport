<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Application\Catalog\StorefrontWarmer;
use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\ExchangeRateRefresher;
use App\Application\Commerce\ExchangeRateUnavailable;
use App\Command\RefreshExchangeRatesCommand;
use App\Command\StorefrontCacheClearCommand;
use App\Command\StorefrontCacheWarmCommand;
use App\Domain\Commerce\ExchangeRate;
use App\Infrastructure\Cache\StorefrontCache;
use App\Infrastructure\Catalog\CachedStorefrontCatalog;
use App\Tests\Double\CollectingMessageBus;
use App\Tests\Double\RecordingStorefrontCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(RefreshExchangeRatesCommand::class)]
#[CoversClass(StorefrontCacheWarmCommand::class)]
#[CoversClass(StorefrontCacheClearCommand::class)]
final class CommerceCommandsTest extends TestCase
{
    private static function rateCommand(callable $refresh): RefreshExchangeRatesCommand
    {
        $provider = self::createStub(ExchangeRateProvider::class);
        $provider->method('refresh')->willReturnCallback($refresh);

        return new RefreshExchangeRatesCommand(new ExchangeRateRefresher($provider));
    }

    private static function storefrontCache(): StorefrontCache
    {
        return new StorefrontCache(
            ['tree' => new ArrayAdapter(), 'list' => new ArrayAdapter(), 'card' => new ArrayAdapter()],
            [
                'tree' => ['fresh' => 3600, 'total' => 86400],
                'list' => ['fresh' => 300, 'total' => 1800],
                'card' => ['fresh' => 900, 'total' => 3600],
            ],
            true,
            'sf',
            2592000,
            new LockFactory(new InMemoryStore()),
            new CollectingMessageBus(),
            new NullLogger(),
            new MockClock('2026-02-01 12:00:00+00'),
        );
    }

    public function testRefreshCommandReportsRatesAndSucceeds(): void
    {
        $command = self::rateCommand(
            static fn (string $currency, \DateTimeImmutable $now): ExchangeRate => new ExchangeRate(
                $currency, 'BYN' === $currency ? '3.45000000' : '4.28000000', 'fake', $now,
            ),
        );
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('PLN 4.28000000 (fake)', $display);
        self::assertStringContainsString('BYN 3.45000000 (fake)', $display);
    }

    public function testRefreshCommandFailsWhenAnyCurrencyFails(): void
    {
        $command = self::rateCommand(
            static function (string $currency, \DateTimeImmutable $now): ExchangeRate {
                if ('BYN' === $currency) {
                    throw new ExchangeRateUnavailable('all sources down');
                }

                return new ExchangeRate($currency, '4.28000000', 'nbp', $now);
            },
        );
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(1, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('PLN 4.28000000', $display);
        self::assertStringContainsString('BYN refresh failed', $display);
    }

    public function testWarmCommandWarmsSelectedLocalesAndPages(): void
    {
        $inner = new RecordingStorefrontCatalog();
        $warmer = new StorefrontWarmer(
            new CachedStorefrontCatalog($inner, self::storefrontCache()),
            ['en', 'ru', 'pl'],
        );
        $command = new StorefrontCacheWarmCommand($warmer, ['en', 'ru', 'pl']);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute(['--pages' => '1', '--locale' => ['en']]));
        self::assertSame(1, $inner->calls['categories']);
        self::assertSame(1, $inner->calls['featured']);
        self::assertSame(1, $inner->calls['page']);
    }

    public function testClearCommandRotatesAllGenerations(): void
    {
        $cache = self::storefrontCache();
        $loads = 0;
        $loader = static function () use (&$loads): int {
            return ++$loads;
        };
        $cache->remember('list', 'k', $loader);
        $cache->remember('tree', 'k', $loader);
        $cache->remember('card', 'k', $loader);
        self::assertSame(3, $loads);

        $tester = new CommandTester(new StorefrontCacheClearCommand($cache));
        self::assertSame(0, $tester->execute([]));

        $cache->remember('list', 'k', $loader);
        $cache->remember('tree', 'k', $loader);
        $cache->remember('card', 'k', $loader);
        self::assertSame(6, $loads, 'Every group must have rotated.');
    }
}
