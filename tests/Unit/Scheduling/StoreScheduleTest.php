<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scheduling;

use App\Application\Catalog\WarmStorefrontCache;
use App\Application\Commerce\RefreshExchangeRates;
use App\Infrastructure\Scheduling\StoreSchedule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\Generator\MessageContext;

#[CoversClass(StoreSchedule::class)]
final class StoreScheduleTest extends TestCase
{
    public function testScheduleContainsExactlyRatesAndWarmEntries(): void
    {
        $schedule = (new StoreSchedule(new ArrayAdapter()))->getSchedule();

        self::assertTrue($schedule->shouldProcessOnlyLastMissedRun());
        self::assertNotNull($schedule->getState(), 'The schedule must be stateful for missed-run handling.');

        $recurring = $schedule->getRecurringMessages();
        self::assertCount(2, $recurring);

        $entries = [];
        $now = new \DateTimeImmutable('2026-02-01T12:00:00+00');
        foreach ($recurring as $message) {
            $context = new MessageContext('default', 'id', $message->getTrigger(), $now);
            foreach ($message->getMessages($context) as $inner) {
                $entries[] = [(string) $message->getTrigger(), $inner];
            }
        }
        self::assertCount(2, $entries);

        $byCron = [];
        foreach ($entries as [$cron, $inner]) {
            $byCron[$cron] = $inner;
        }

        self::assertArrayHasKey('0 * * * *', $byCron, 'Hourly exchange-rate refresh is missing.');
        self::assertInstanceOf(RefreshExchangeRates::class, $byCron['0 * * * *']);
        self::assertArrayHasKey('*/5 * * * *', $byCron, 'Five-minute storefront warm is missing.');
        $warm = $byCron['*/5 * * * *'];
        self::assertInstanceOf(WarmStorefrontCache::class, $warm);
        self::assertSame(2, $warm->pages);

        // Nothing else is scheduled — in particular no payment reconciliation.
        foreach ($entries as [$cron, $inner]) {
            self::assertStringNotContainsStringIgnoringCase('shoppay', $inner::class);
            self::assertStringNotContainsStringIgnoringCase('shopify', $inner::class);
        }
    }
}
