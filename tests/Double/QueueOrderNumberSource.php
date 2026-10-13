<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Application\Ordering\OrderNumberSource;

/**
 * Test-env OrderNumberSource wired via config/services_test.yaml. Tests prime
 * a static queue of full order numbers (e.g. 'SP-DEADBEEF'); once drained it
 * falls back to random generation like the production source.
 */
final class QueueOrderNumberSource implements OrderNumberSource
{
    /** @var list<string> */
    private static array $queue = [];

    public static function prime(string ...$numbers): void
    {
        self::$queue = array_values($numbers);
    }

    public static function reset(): void
    {
        self::$queue = [];
    }

    public function next(): string
    {
        return array_shift(self::$queue) ?? 'SP-'.strtoupper(bin2hex(random_bytes(4)));
    }
}
