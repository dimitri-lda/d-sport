<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\DomainException;
use App\Domain\Identity\Entity\User;

/**
 * Signed email-verification URLs. The signature binds user id, the hash of the
 * user's current email and the expiry, so links die on email change, expiry,
 * or any tampering — all verified with hash_equals.
 */
final class VerificationLinkSigner
{
    private const LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly string $appPublicUrl,
        private readonly string $appSecret,
    ) {
    }

    /** Absolute verify URL valid for one hour. */
    public function generate(User $user, \DateTimeImmutable $now): string
    {
        $id = $user->getId();
        if (null === $id) {
            throw new DomainException('Cannot sign a verification link for an unpersisted user.');
        }
        $expires = $now->getTimestamp() + self::LIFETIME_SECONDS;
        $hash = $this->emailHash($user->getEmail());
        $signature = $this->signature($id, $hash, $expires);

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
     * expiry is always rejected.
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

        return hash_equals($this->signature($id, $expectedHash, $expiresAt), $signature);
    }

    private function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower($email));
    }

    private function signature(int $id, string $hash, int $expires): string
    {
        return hash_hmac('sha256', $id.'|'.$hash.'|'.$expires, $this->appSecret);
    }
}
