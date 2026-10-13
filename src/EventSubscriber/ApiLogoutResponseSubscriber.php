<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * The firewall logout already invalidates the session; API callers get a
 * bare 204 instead of the default redirect to the logout target.
 */
final class ApiLogoutResponseSubscriber implements EventSubscriberInterface
{
    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        // Runs before DefaultLogoutListener (priority 64) sets a redirect.
        return [LogoutEvent::class => ['onLogout', 128]];
    }

    public function onLogout(LogoutEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->setResponse(new Response('', Response::HTTP_NO_CONTENT));
        }
    }
}
