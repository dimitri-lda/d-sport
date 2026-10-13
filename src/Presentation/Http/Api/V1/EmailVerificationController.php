<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Identity\AccountMailer;
use App\Application\Identity\VerificationLinkSigner;
use App\Domain\Identity\Entity\User;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class EmailVerificationController
{
    public function __construct(
        private readonly VerificationLinkSigner $signer,
        private readonly AccountMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'limiter.email_verification')]
        private readonly RateLimiterFactory $resendLimiter,
    ) {
    }

    /**
     * The single state-changing GET in the API: a signed link from the
     * verification email. Success redirects back into the SPA.
     */
    #[Route(
        '/api/v1/auth/email/verify/{id}/{hash}',
        name: 'api_v1_auth_email_verify',
        requirements: ['id' => '\d+', 'hash' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function verify(
        Request $request,
        #[CurrentUser] User $user,
        int $id,
        string $hash,
    ): RedirectResponse {
        $now = self::now();
        $valid = $this->signer->verify(
            $id,
            $hash,
            $request->query->get('expires'),
            $request->query->get('signature'),
            $user,
            $now,
        );
        if (!$valid) {
            throw new ApiProblem('verification_link_invalid', 'The verification link is invalid or has expired.', 403);
        }

        if (!$user->isEmailVerified()) {
            $user->verifyEmail($now);
            $this->entityManager->flush();
        }

        return new RedirectResponse('/verify-email?verified=1', Response::HTTP_FOUND);
    }

    #[Route(
        '/api/v1/auth/email/verification-notification',
        name: 'api_v1_auth_email_verification_resend',
        methods: ['POST'],
    )]
    public function resend(#[CurrentUser] User $user): JsonResponse
    {
        $limit = $this->resendLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            throw new ApiProblem('too_many_requests', 'Too many verification emails requested. Please wait.', 429);
        }

        if (!$user->isEmailVerified()) {
            $this->mailer->sendVerification($user, $this->signer->generate($user, self::now()));
        }

        return new JsonResponse(['data' => ['message' => 'verification_link_sent']], 202);
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
