<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Cart\CartManager;
use App\Application\Identity\RegisterInput;
use App\Application\Identity\RegistrationService;
use App\EventSubscriber\ApiCsrfSubscriber;
use App\Presentation\Http\Api\ContextView;
use App\Presentation\Http\JsonBody;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class RegisterController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly Security $security,
        private readonly ContextView $contextView,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly CartManager $cartManager,
    ) {
    }

    #[Route('/api/v1/auth/register', name: 'api_v1_auth_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $input = new RegisterInput(
            JsonBody::string($body, 'display_name') ?? '',
            JsonBody::string($body, 'email') ?? '',
            JsonBody::string($body, 'password') ?? '',
            JsonBody::string($body, 'password_confirmation') ?? '',
            JsonBody::bool($body, 'accepted_terms') ?? false,
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $user = $this->registration->register($input, $now);

        // Authenticate through the api firewall: its login success path
        // migrates the session id (fixation protection).
        $this->security->login($user, 'security.authenticator.json_login.api', 'api');
        // Guest lines merge into the new account's cart (overwrite semantics).
        if ($request->hasSession()) {
            $this->cartManager->mergeGuestIntoUser($request->getSession(), $user);
        }
        // Fresh token for the rotated session, returned inside the context.
        $this->csrfTokenManager->refreshToken(ApiCsrfSubscriber::TOKEN_ID);

        return new JsonResponse(['data' => $this->contextView->current($request)], 201);
    }
}
