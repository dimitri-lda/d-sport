<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * The session-fixation strategy migrates the session and clears session CSRF
 * tokens after the success handler has built its response. Refresh the API
 * token here, once clearing has run, and rewrite the advertised csrf_token so
 * the client's first mutating request validates against the stored token.
 */
final class LoginCsrfRefreshSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Runs after SessionStrategyListener (0) and RememberMeListener (-64).
        return [LoginSuccessEvent::class => ['onLoginSuccess', -128]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $response = $event->getResponse();
        if (null === $response) {
            return;
        }
        $payload = json_decode($response->getContent() ?: '', true);
        if (!\is_array($payload)
            || !\is_array($payload['data'] ?? null)
            || !\is_string($payload['data']['csrf_token'] ?? null)
        ) {
            return;
        }
        $payload['data']['csrf_token'] = $this->csrfTokenManager
            ->refreshToken(ApiCsrfSubscriber::TOKEN_ID)
            ->getValue();
        $response->setContent(json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
