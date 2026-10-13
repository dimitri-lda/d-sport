<?php

declare(strict_types=1);

namespace App\Health;

final readonly class RedisProbe implements DependencyProbe
{
    public function __construct(private \Redis $redis)
    {
    }

    public function check(): void
    {
        if (false === $this->redis->ping()) {
            throw new \RuntimeException('Cache connection is unavailable.');
        }
    }
}
