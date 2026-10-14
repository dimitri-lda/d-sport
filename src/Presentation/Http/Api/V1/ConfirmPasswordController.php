<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Identity\PasswordConfirmationService;
use App\Domain\Identity\Entity\User;
use App\Presentation\Http\ApiProblem;
use App\Presentation\Http\JsonBody;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ConfirmPasswordController
{
    public function __construct(
        private readonly PasswordConfirmationService $confirmations,
        #[Autowire(service: 'limiter.password_confirmation')]
        private readonly RateLimiterFactory $confirmLimiter,
    ) {
    }

    #[Route('/api/v1/auth/confirm-password', name: 'api_v1_auth_confirm_password_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $session = $request->getSession();
        $now = self::now();

        return new JsonResponse([
            'data' => [
                'confirmed' => $this->confirmations->isConfirmed($session, $now),
                'confirmed_at' => $this->confirmations->confirmedAt($session),
            ],
        ]);
    }

    #[Route('/api/v1/auth/confirm-password', name: 'api_v1_auth_confirm_password', methods: ['POST'])]
    public function confirm(Request $request, #[CurrentUser] User $user): Response
    {
        // Keyed by the authenticated user: without a cap this endpoint is an
        // unlimited password-guessing oracle against a live session.
        $limit = $this->confirmLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiProblem('too_many_requests', 'Too many password confirmation attempts. Please wait.', 429);
        }

        $password = JsonBody::string(JsonBody::decode($request), 'password') ?? '';

        if (!$this->confirmations->confirm($user, $password, $request->getSession(), self::now())) {
            throw new ApiProblem('validation_failed', 'The given password is invalid.', 422, ['password' => ['The password is incorrect.']]);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
