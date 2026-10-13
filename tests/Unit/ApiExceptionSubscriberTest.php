<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiExceptionSubscriberTest extends TestCase
{
    public function testInternalExceptionDetailsNeverReachClient(): void
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/technical-test'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('Database password: do-not-expose'),
        );

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"internal_error","message":"Internal Server Error"}}',
            (string) $response->getContent(),
        );
    }
}
