<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Presentation\Http\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Requires a valid `X-CSRF-Token` header (token id `api`) on every mutating
 * /api/v1 request.
 *
 * The Shopify orders webhook will need an exemption here — it authenticates
 * through the provider signature instead of a session token — but that
 * exemption must come back together with the signature verification that
 * justifies it, not before.
 */
final class ApiCsrfSubscriber implements EventSubscriberInterface
{
    public const TOKEN_ID = 'api';

    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/v1/')) {
            return;
        }
        $safe = [Request::METHOD_GET, Request::METHOD_HEAD, Request::METHOD_OPTIONS];
        if (\in_array($request->getMethod(), $safe, true)) {
            return;
        }
        $token = $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            throw new ApiProblem('csrf_invalid', 'The CSRF token is invalid.', 419);
        }
    }
}
