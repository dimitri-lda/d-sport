<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\DomainException;
use App\EventSubscriber\ApiExceptionSubscriber;
use App\Presentation\Http\ApiProblem;
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

    public function testApiProblemCarriesCodeStatusMessageAndFields(): void
    {
        $event = $this->event('/api/v1/orders', new ApiProblem('stock_unavailable', 'Not enough stock.', 422, [
            'items' => ['Variant SKU-1 is out of stock.'],
        ]));

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"stock_unavailable","message":"Not enough stock.",'
            .'"fields":{"items":["Variant SKU-1 is out of stock."]}}}',
            (string) $response->getContent(),
        );
    }

    public function testApiProblemOmitsFieldsWhenEmpty(): void
    {
        $event = $this->event('/api/v1/cart', new ApiProblem('cart_not_found', 'Cart not found.', 404));

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"cart_not_found","message":"Cart not found."}}',
            (string) $response->getContent(),
        );
    }

    public function testDomainExceptionMapsTo422WithSafeCode(): void
    {
        $event = $this->event('/api/v1/orders/1', new DomainException('Order cannot transition.'));

        (new ApiExceptionSubscriber())->onException($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error":{"code":"domain_rule_violated","message":"Order cannot transition."}}',
            (string) $response->getContent(),
        );
    }

    public function testNonApiPathStaysUntouched(): void
    {
        $event = $this->event('/some-page', new \RuntimeException('boom'));

        (new ApiExceptionSubscriber())->onException($event);

        self::assertNull($event->getResponse());
    }

    private function event(string $path, \Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
