<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Stamps `Cache-Control: no-store` on every /api/v1 response — they carry
 * the CSRF token and account PII, so neither shared nor private caches may
 * keep a copy. A Cache-Control header set deliberately on the response
 * (anything beyond HttpFoundation's "no-cache, private" default) wins.
 */
final class ApiCacheControlSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', 0]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/v1/')) {
            return;
        }
        $response = $event->getResponse();
        $cacheControl = $response->headers->get('Cache-Control');
        if (null !== $cacheControl && 'no-cache, private' !== $cacheControl) {
            return;
        }
        $response->headers->set('Cache-Control', 'no-store');
    }
}
