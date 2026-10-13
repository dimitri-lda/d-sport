<?php

declare(strict_types=1);

namespace App\Presentation\Http\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/** Uniform failure envelope: throttling never leaks a different shape. */
final class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => [
                'code' => 'invalid_credentials',
                'message' => 'The supplied credentials are invalid.',
            ]],
            Response::HTTP_UNAUTHORIZED,
        );
    }
}
