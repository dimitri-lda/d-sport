<?php

declare(strict_types=1);

namespace App\Presentation\Http\Security;

use App\Application\Cart\CartManager;
use App\Domain\Identity\Entity\User;
use App\Presentation\Http\Api\ContextView;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Successful JSON login: merge any guest cart and hand back the full context.
 * The session-fixation strategy migrates the session and clears CSRF tokens
 * after this handler runs; LoginCsrfRefreshSubscriber rewrites the advertised
 * token afterwards.
 */
final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly ContextView $contextView,
        private readonly CartManager $cartManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();
        if ($user instanceof User && $request->hasSession()) {
            try {
                $this->cartManager->mergeGuestIntoUser($request->getSession(), $user);
            } catch (\Throwable $exception) {
                // The merge is best-effort: losing the guest cart is a far
                // smaller harm than a broken cart blocking authentication.
                $this->logger->warning('Guest cart merge failed during login.', [
                    'user_id' => $user->getId(),
                    'exception' => $exception::class,
                ]);
            }
        }

        // The session-fixation strategy runs after this handler and clears
        // session CSRF tokens; LoginCsrfRefreshSubscriber refreshes and rewrites
        // the advertised token once that listener has run.
        return new JsonResponse(['data' => $this->contextView->current($request)]);
    }
}
