<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Health\DependencyProbe;
use App\Health\ReadinessChecker;
use PHPUnit\Framework\TestCase;

final class ReadinessCheckerTest extends TestCase
{
    public function testRedisIsStillCheckedAfterPostgresFails(): void
    {
        $database = $this->createMock(DependencyProbe::class);
        $database->expects(self::once())->method('check')->willThrowException(new \RuntimeException('secret'));
        $cache = $this->createMock(DependencyProbe::class);
        $cache->expects(self::once())->method('check');

        self::assertSame([
            'status' => 'error',
            'checks' => ['postgresql' => 'error', 'redis' => 'ok'],
        ], (new ReadinessChecker($database, $cache))->check());
    }

    public function testRedisFailureMakesApplicationUnready(): void
    {
        $database = $this->createStub(DependencyProbe::class);
        $cache = $this->createStub(DependencyProbe::class);
        $cache->method('check')->willThrowException(new \RuntimeException('redis-password'));

        self::assertSame([
            'status' => 'error',
            'checks' => ['postgresql' => 'ok', 'redis' => 'error'],
        ], (new ReadinessChecker($database, $cache))->check());
    }
}
