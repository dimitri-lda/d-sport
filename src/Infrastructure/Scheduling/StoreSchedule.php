<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduling;

use App\Application\Catalog\WarmStorefrontCache;
use App\Application\Commerce\RefreshExchangeRates;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The store's recurring jobs: hourly exchange-rate refresh and a five
 * minute storefront warm. Stateful in the shared cache pool so a missed
 * run is not replayed more than once.
 */
#[AsSchedule('default')]
final class StoreSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::cron('0 * * * *', new RefreshExchangeRates()))
            ->add(RecurringMessage::cron('*/5 * * * *', new WarmStorefrontCache(2)))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
