<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ApiCsrfSubscriber;
use App\Presentation\Http\ApiProblem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[CoversClass(ApiCsrfSubscriber::class)]
final class ApiCsrfSubscriberTest extends TestCase
{
    private function event(string $method, string $path, ?string $token = null): RequestEvent
    {
        $request = Request::create($path, $method);
        if (null !== $token) {
            $request->headers->set('X-CSRF-Token', $token);
        }
        $kernel = self::createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function subscriber(bool $valid): ApiCsrfSubscriber
    {
        $manager = self::createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturn($valid);

        return new ApiCsrfSubscriber($manager);
    }

    public function testGetRequestsPassWithoutToken(): void
    {
        $this->subscriber(false)->onKernelRequest($this->event('GET', '/api/v1/products'));
        $this->subscriber(false)->onKernelRequest($this->event('HEAD', '/api/v1/context'));
        $this->addToAssertionCount(2);
    }

    public function testNonApiPathsPass(): void
    {
        $this->subscriber(false)->onKernelRequest($this->event('POST', '/login'));
        $this->subscriber(false)->onKernelRequest($this->event('POST', '/api/v2/products'));
        $this->addToAssertionCount(2);
    }

    public function testMutatingRequestWithoutValidTokenIsRejected(): void
    {
        try {
            $this->subscriber(false)->onKernelRequest($this->event('POST', '/api/v1/cart/items'));
            self::fail('Expected ApiProblem.');
        } catch (ApiProblem $problem) {
            self::assertSame(419, $problem->status);
            self::assertSame('csrf_invalid', $problem->errorCode);
        }
    }

    public function testMutatingRequestWithValidTokenPasses(): void
    {
        $this->subscriber(true)->onKernelRequest($this->event('PUT', '/api/v1/preferences', 'token-abc'));
        $this->subscriber(true)->onKernelRequest($this->event('PATCH', '/api/v1/cart', 'token-abc'));
        $this->subscriber(true)->onKernelRequest($this->event('DELETE', '/api/v1/cart/items/1', 'token-abc'));
        $this->addToAssertionCount(3);
    }

    public function testWebhookPathsAreNotExempt(): void
    {
        // No exemption may exist before the signature verification that would
        // justify it — the Shopify orders webhook route does not exist yet.
        foreach (['/api/v1/webhooks/shopify/orders', '/api/v1/webhooks/other'] as $path) {
            try {
                $this->subscriber(false)->onKernelRequest($this->event('POST', $path));
                self::fail('Expected ApiProblem.');
            } catch (ApiProblem $problem) {
                self::assertSame(419, $problem->status);
            }
        }
    }
}
