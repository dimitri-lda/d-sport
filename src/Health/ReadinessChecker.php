<?php

declare(strict_types=1);

namespace App\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ReadinessChecker
{
    public function __construct(
        #[Autowire(service: PostgresProbe::class)] private DependencyProbe $postgres,
        #[Autowire(service: RedisProbe::class)] private DependencyProbe $redis,
    ) {
    }

    /** @return array{status: 'ok'|'error', checks: array{postgresql: 'ok'|'error', redis: 'ok'|'error'}} */
    public function check(): array
    {
        $postgresql = $this->probe($this->postgres);
        $redis = $this->probe($this->redis);

        return [
            'status' => 'ok' === $postgresql && 'ok' === $redis ? 'ok' : 'error',
            'checks' => ['postgresql' => $postgresql, 'redis' => $redis],
        ];
    }

    /** @return 'ok'|'error' */
    private function probe(DependencyProbe $dependency): string
    {
        try {
            $dependency->check();

            return 'ok';
        } catch (\Throwable) {
            return 'error';
        }
    }
}
