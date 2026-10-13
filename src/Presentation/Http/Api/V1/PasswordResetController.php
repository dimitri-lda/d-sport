<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Identity\PasswordResetService;
use App\Application\Identity\ResetPasswordInput;
use App\Presentation\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PasswordResetController
{
    public function __construct(private readonly PasswordResetService $resets)
    {
    }

    #[Route('/api/v1/auth/forgot-password', name: 'api_v1_auth_forgot_password', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $email = JsonBody::string(JsonBody::decode($request), 'email') ?? '';

        // Always the same answer — the response must never reveal whether the
        // account exists.
        $this->resets->requestReset($email, self::now());

        return new JsonResponse(['data' => ['message' => 'password_reset_if_known']], 202);
    }

    #[Route('/api/v1/auth/reset-password', name: 'api_v1_auth_reset_password', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        $body = JsonBody::decode($request);
        $this->resets->reset(new ResetPasswordInput(
            JsonBody::string($body, 'email') ?? '',
            JsonBody::string($body, 'token') ?? '',
            JsonBody::string($body, 'password') ?? '',
            JsonBody::string($body, 'password_confirmation') ?? '',
        ), self::now());

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
