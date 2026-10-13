<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Flushes the shared Redis storage so rate-limiter windows and cache
 * entries stay per-test. Done without the kernel — getContainer() must
 * not boot before createClient(). Sessions are PDO-backed and unaffected.
 */
trait FlushesRedis
{
    private static function flushRedis(): void
    {
        $dsn = (array) parse_url((string) getenv('REDIS_URL'));
        $redis = new \Redis();
        $redis->connect((string) ($dsn['host'] ?? '127.0.0.1'), (int) ($dsn['port'] ?? 6379));
        $redis->flushDB();
        $redis->close();
    }
}
