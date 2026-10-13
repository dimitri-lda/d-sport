<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Repository\PasswordResetTokenRepository;
use App\Domain\Identity\Repository\UserRepository;
use App\Presentation\Http\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Password reset flow. The forgot endpoint is intentionally generic — the
 * response must never reveal whether an account exists. Only SHA-256 hashes
 * of single-use tokens reach the database.
 */
final class PasswordResetService
{
    private const TOKEN_LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccountMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $appPublicUrl,
    ) {
    }

    /** Issues a reset link when the email is known; silently no-ops otherwise. */
    public function requestReset(string $email, \DateTimeImmutable $now): void
    {
        $user = $this->users->findByEmail(mb_strtolower(trim($email)));
        if (null === $user) {
            return;
        }

        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = $now->add(new \DateInterval('PT'.self::TOKEN_LIFETIME_SECONDS.'S'));
        $this->tokens->replaceFor($user, hash('sha256', $rawToken), $expiresAt);

        $url = \sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim($this->appPublicUrl, '/'),
            rawurlencode($rawToken),
            rawurlencode($user->getEmail()),
        );
        $this->mailer->sendPasswordReset($user, $url);
    }

    public function reset(ResetPasswordInput $input, \DateTimeImmutable $now): void
    {
        $email = mb_strtolower(trim($input->email));
        $fields = [];
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = ['A valid email address is required.'];
        }
        if ('' === $input->token) {
            $fields['token'] = ['The reset token is invalid or has expired.'];
        }
        $violations = PasswordPolicy::violations($input->password);
        if ([] !== $violations) {
            $fields['password'] = $violations;
        }
        if ($input->password !== $input->passwordConfirmation) {
            $fields['password_confirmation'] = ['The password confirmation does not match.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given password reset data is invalid.', 422, $fields);
        }

        $token = $this->tokens->findValid($email, hash('sha256', $input->token), $now);
        if (null === $token) {
            throw new ApiProblem('password_reset_invalid', 'The reset token is invalid or has expired.', 422, ['token' => ['The reset token is invalid or has expired.']]);
        }

        $user = $token->getUser();
        $user->changePassword($this->hasher->hashPassword($user, $input->password));
        $this->entityManager->remove($token);
        $this->entityManager->flush();
    }
}
