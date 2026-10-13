<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Commerce\CountryCatalog;
use App\Domain\Identity\Entity\SavedAddress;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\SavedAddressRepository;
use App\Domain\Identity\Repository\UserRepository;
use App\Presentation\Http\ApiProblem;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Account self-management. An email change always drops verification and
 * sends a fresh signed link for the new address; password change and account
 * deletion require the current password.
 */
final class ProfileService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SavedAddressRepository $addresses,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AccountMailer $mailer,
        private readonly VerificationLinkSigner $signer,
        private readonly EntityManagerInterface $entityManager,
        private readonly CountryCatalog $countries,
    ) {
    }

    public function updateProfile(User $user, string $displayName, string $email, \DateTimeImmutable $now): void
    {
        $displayName = trim($displayName);
        $email = mb_strtolower(trim($email));

        $fields = [];
        if ('' === $displayName || mb_strlen($displayName) > 120) {
            $fields['display_name'] = ['The display name is required (1-120 characters).'];
        }
        if ('' === $email || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $fields['email'] = ['A valid email address is required.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given profile data is invalid.', 422, $fields);
        }

        $emailChanged = $email !== mb_strtolower($user->getEmail());
        if ($emailChanged) {
            $existing = $this->users->findByEmail($email);
            if (null !== $existing && $existing->getId() !== $user->getId()) {
                throw self::emailTaken();
            }
        }

        $user->changeProfile($displayName, $email);
        if ($emailChanged) {
            $user->unverifyEmail();
        }
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw self::emailTaken();
        }
        if ($emailChanged) {
            $this->mailer->sendVerification($user, $this->signer->generate($user, $now));
        }
    }

    public function updateAddress(User $user, AddressInput $input): SavedAddress
    {
        $fields = self::addressFields($input);
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given address is invalid.', 422, $fields);
        }
        $country = strtoupper(trim($input->country));
        if (!$this->countries->isSupported($country)) {
            throw new ApiProblem('validation_failed', 'The given address is invalid.', 422, ['country' => ['The selected country is not supported.']]);
        }

        $address = $this->addresses->findForUser($user);
        if (null === $address) {
            $address = new SavedAddress(
                $user,
                $input->firstName,
                $input->lastName,
                $input->line1,
                $input->line2,
                $input->city,
                $input->postalCode,
                $country,
            );
            $this->entityManager->persist($address);
        } else {
            $address->update(
                $input->firstName,
                $input->lastName,
                $input->line1,
                $input->line2,
                $input->city,
                $input->postalCode,
                $country,
            );
        }
        $this->entityManager->flush();

        return $address;
    }

    public function changePassword(User $user, string $currentPassword, string $password, string $confirmation): void
    {
        $fields = [];
        if (!$this->hasher->isPasswordValid($user, $currentPassword)) {
            $fields['current_password'] = ['The current password is incorrect.'];
        }
        $violations = PasswordPolicy::violations($password);
        if ([] !== $violations) {
            $fields['password'] = $violations;
        }
        if ($password !== $confirmation) {
            $fields['password_confirmation'] = ['The password confirmation does not match.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given password data is invalid.', 422, $fields);
        }

        $user->changePassword($this->hasher->hashPassword($user, $password));
        $this->entityManager->flush();
    }

    public function deleteAccount(User $user, string $password): void
    {
        if (!$this->hasher->isPasswordValid($user, $password)) {
            throw new ApiProblem('validation_failed', 'The given password is invalid.', 422, ['password' => ['The current password is incorrect.']]);
        }

        // saved_addresses, password reset tokens and carts cascade; orders keep
        // history via ON DELETE SET NULL.
        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($user): void {
            $em->remove($user);
            $em->flush();
        });
    }

    /** @return array<string, list<string>> */
    private static function addressFields(AddressInput $input): array
    {
        $fields = [];
        if ('' === trim($input->firstName) || mb_strlen($input->firstName) > 80) {
            $fields['first_name'] = ['The first name is required (1-80 characters).'];
        }
        if ('' === trim($input->lastName) || mb_strlen($input->lastName) > 80) {
            $fields['last_name'] = ['The last name is required (1-80 characters).'];
        }
        if ('' === trim($input->line1) || mb_strlen($input->line1) > 180) {
            $fields['line1'] = ['The address line is required (1-180 characters).'];
        }
        if (null !== $input->line2 && mb_strlen($input->line2) > 180) {
            $fields['line2'] = ['The second address line must be at most 180 characters.'];
        }
        if ('' === trim($input->city) || mb_strlen($input->city) > 80) {
            $fields['city'] = ['The city is required (1-80 characters).'];
        }
        if ('' === trim($input->postalCode) || mb_strlen($input->postalCode) > 20) {
            $fields['postal_code'] = ['The postal code is required (1-20 characters).'];
        }
        if (1 !== preg_match('/^[A-Za-z]{2}$/', $input->country)) {
            $fields['country'] = ['The country must be a two-letter ISO code.'];
        }

        return $fields;
    }

    private static function emailTaken(): ApiProblem
    {
        return new ApiProblem('email_taken', 'An account already exists for this email address.', 422, [
            'email' => ['An account already exists for this email address.'],
        ]);
    }
}
