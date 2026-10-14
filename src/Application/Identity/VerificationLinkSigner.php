<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\DomainException;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Signed email-verification URLs. The signature binds user id, an HMAC of
 * the user's current email keyed on the app secret, the expiry and the
 * user's verification nonce, so links die on email change, expiry,
 * verification (the nonce rotates on success) or any tampering — all
 * verified with hash_equals. The keyed email hash is no offline oracle for
 * guessing the address, and the nonce makes every link single-use.
 *
 * Introducing the nonce invalidates every link issued before this change —
 * accepted as a demo tradeoff: unverified users simply request a resend.
 */
final class VerificationLinkSigner
{
    private const LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly string $appPublicUrl,
        private readonly string $appSecret,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** Absolute verify URL valid for one hour and one successful use. */
    public function generate(User $user, \DateTimeImmutable $now): string
    {
        $id = $user->getId();
        if (null === $id) {
            throw new DomainException('Cannot sign a verification link for an unpersisted user.');
        }
        $nonce = $user->getEmailVerificationNonce();
        if (null === $nonce) {
            // First link for this user: the nonce must be durable before
            // the email can possibly be clicked.
            $nonce = $user->mintEmailVerificationNonce();
            $this->entityManager->flush();
        }
        $expires = $now->getTimestamp() + self::LIFETIME_SECONDS;
        $hash = $this->emailHash($user->getEmail());
        $signature = $this->signature($id, $hash, $expires, $nonce);

        return \sprintf(
            '%s/api/v1/auth/email/verify/%d/%s?expires=%d&signature=%s',
            rtrim($this->appPublicUrl, '/'),
            $id,
            $hash,
            $expires,
            $signature,
        );
    }

    /**
     * Constant-time check of every link component. Missing or non-numeric
     * expiry is always rejected, as is a user without a nonce (no link was
     * ever issued to them). A failed check never touches the nonce, so a
     * bad attempt cannot burn a pending link.
     */
    public function verify(
        int $id,
        string $hash,
        ?string $expires,
        ?string $signature,
        User $user,
        \DateTimeImmutable $now,
    ): bool {
        if ($user->getId() !== $id) {
            return false;
        }
        if (null === $expires || null === $signature || 1 !== preg_match('/^\d+$/', $expires)) {
            return false;
        }
        $expiresAt = (int) $expires;
        if ($expiresAt < $now->getTimestamp()) {
            return false;
        }
        $expectedHash = $this->emailHash($user->getEmail());
        if (!hash_equals($expectedHash, $hash)) {
            return false;
        }
        $nonce = $user->getEmailVerificationNonce();
        if (null === $nonce) {
            return false;
        }

        return hash_equals($this->signature($id, $expectedHash, $expiresAt, $nonce), $signature);
    }

    private function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower($email), $this->appSecret);
    }

    private function signature(int $id, string $hash, int $expires, string $nonce): string
    {
        return hash_hmac('sha256', $id.'|'.$hash.'|'.$expires.'|'.$nonce, $this->appSecret);
    }
}
