<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\UserRepository;
use App\Presentation\Http\ApiProblem;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Self-service account registration. Emails are normalized to lowercase
 * before comparison and persistence so the lower(email) index enforces
 * case-insensitive uniqueness.
 */
final class RegistrationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly VerificationLinkSigner $verificationLinks,
        private readonly AccountMailer $mailer,
    ) {
    }

    public function register(RegisterInput $input, \DateTimeImmutable $now): User
    {
        $displayName = trim($input->displayName);
        $email = mb_strtolower(trim($input->email));

        $fields = [];
        if ('' === $displayName || mb_strlen($displayName) > 120) {
            $fields['display_name'] = ['The display name is required (1-120 characters).'];
        }
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = ['A valid email address is required.'];
        }
        $violations = PasswordPolicy::violations($input->password);
        if ([] !== $violations) {
            $fields['password'] = $violations;
        }
        if ($input->password !== $input->passwordConfirmation) {
            $fields['password_confirmation'] = ['The password confirmation does not match.'];
        }
        if (!$input->acceptedTerms) {
            $fields['accepted_terms'] = ['The terms of service must be accepted to register.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given registration data is invalid.', 422, $fields);
        }
        if (null !== $this->users->findByEmail($email)) {
            throw self::emailTaken();
        }

        $user = new User($email, 'pending', $displayName);
        $user->changePassword($this->hasher->hashPassword($user, $input->password));
        $user->acceptTerms($now);
        $this->entityManager->persist($user);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::emailTaken();
        }

        // Best-effort: transport failures are logged inside the mailer and
        // must not turn a successful registration into an error response.
        $this->mailer->sendVerification($user, $this->verificationLinks->generate($user, $now));

        return $user;
    }

    private static function emailTaken(): ApiProblem
    {
        return new ApiProblem('email_taken', 'An account already exists for this email address.', 422, [
            'email' => ['An account already exists for this email address.'],
        ]);
    }
}
