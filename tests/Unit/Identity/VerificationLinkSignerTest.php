<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Application\Identity\VerificationLinkSigner;
use App\Domain\Identity\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(VerificationLinkSigner::class)]
final class VerificationLinkSignerTest extends TestCase
{
    private const PUBLIC_URL = 'https://shop.example.test';
    private const SECRET = 'unit-test-secret';

    private VerificationLinkSigner $signer;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->signer = new VerificationLinkSigner(
            self::PUBLIC_URL,
            self::SECRET,
            $this->createMock(EntityManagerInterface::class),
        );
        $this->now = new \DateTimeImmutable('2026-02-01T12:00:00+00:00');
    }

    private static function user(int $id, string $email = 'customer@example.test'): User
    {
        $user = new User($email, 'hash', 'Customer');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private static function emailHash(string $email = 'customer@example.test'): string
    {
        return hash_hmac('sha256', mb_strtolower($email), self::SECRET);
    }

    /** @return array{path: string, query: array<string, string>} */
    private static function parts(string $url): array
    {
        $parsed = parse_url($url);
        self::assertIsArray($parsed);
        parse_str($parsed['query'] ?? '', $query);

        return ['path' => $parsed['path'] ?? '', 'query' => $query];
    }

    public function testGeneratedLinkVerifies(): void
    {
        $user = self::user(7);
        $parts = self::parts($this->signer->generate($user, $this->now));

        // The URL carries the keyed email hash — never a bare sha256 —
        // and the signature binds id, hash, expiry and the stored nonce.
        self::assertSame('/api/v1/auth/email/verify/7/'.self::emailHash(), $parts['path']);
        $expires = $parts['query']['expires'];
        $signature = $parts['query']['signature'];
        self::assertSame((string) ($this->now->getTimestamp() + 3600), $expires);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $signature);
        $nonce = $user->getEmailVerificationNonce();
        self::assertIsString($nonce);
        self::assertSame(
            hash_hmac('sha256', '7|'.self::emailHash().'|'.$expires.'|'.$nonce, self::SECRET),
            $signature,
        );

        self::assertTrue($this->signer->verify(7, self::emailHash(), $expires, $signature, $user, $this->now));
    }

    public function testLinkIsSingleUse(): void
    {
        $user = self::user(7);
        $parts = self::parts($this->signer->generate($user, $this->now));
        $expires = $parts['query']['expires'];
        $signature = $parts['query']['signature'];

        self::assertTrue($this->signer->verify(7, self::emailHash(), $expires, $signature, $user, $this->now));

        // Consuming the link rotates the nonce (User::verifyEmail); the
        // replayed link — and every other outstanding one — now fails.
        $user->verifyEmail($this->now);
        self::assertFalse($this->signer->verify(7, self::emailHash(), $expires, $signature, $user, $this->now));
    }

    public function testFailedAttemptDoesNotBurnTheLink(): void
    {
        $user = self::user(7);
        $parts = self::parts($this->signer->generate($user, $this->now));
        $expires = $parts['query']['expires'];

        // A grief attempt must not rotate the nonce.
        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            $expires,
            str_repeat('0', 64),
            $user,
            $this->now,
        ));
        self::assertTrue($this->signer->verify(
            7,
            self::emailHash(),
            $expires,
            $parts['query']['signature'],
            $user,
            $this->now,
        ));
    }

    public function testUserWithoutNonceCannotVerify(): void
    {
        $user = self::user(7);
        // No link was ever generated for this user.
        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            (string) ($this->now->getTimestamp() + 3600),
            str_repeat('a', 64),
            $user,
            $this->now,
        ));
    }

    public function testExpiredLinkRejected(): void
    {
        $user = self::user(7);
        $parts = self::parts($this->signer->generate($user, $this->now));
        $later = $this->now->add(new \DateInterval('PT1H1S'));

        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            $parts['query']['expires'],
            $parts['query']['signature'],
            $user,
            $later,
        ));
    }

    public function testTamperedSignatureAndHashRejected(): void
    {
        $user = self::user(7);
        $parts = self::parts($this->signer->generate($user, $this->now));
        $expires = $parts['query']['expires'];

        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            $expires,
            str_repeat('0', 64),
            $user,
            $this->now,
        ));
        self::assertFalse($this->signer->verify(
            7,
            self::emailHash('other@example.test'),
            $expires,
            $parts['query']['signature'],
            $user,
            $this->now,
        ));
    }

    public function testEmailChangeInvalidatesLink(): void
    {
        $user = self::user(7);
        $parts = self::parts($this->signer->generate($user, $this->now));
        $user->changeProfile('Customer', 'new@example.test');

        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            $parts['query']['expires'],
            $parts['query']['signature'],
            $user,
            $this->now,
        ));
    }

    public function testWrongUserAndMissingExpiryRejected(): void
    {
        $user = self::user(7);
        $other = self::user(8);
        $other->mintEmailVerificationNonce();
        $parts = self::parts($this->signer->generate($user, $this->now));

        self::assertFalse($this->signer->verify(
            8,
            self::emailHash(),
            $parts['query']['expires'],
            $parts['query']['signature'],
            $other,
            $this->now,
        ));
        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            null,
            $parts['query']['signature'],
            $user,
            $this->now,
        ));
        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            'tomorrow',
            $parts['query']['signature'],
            $user,
            $this->now,
        ));
        self::assertFalse($this->signer->verify(
            7,
            self::emailHash(),
            $parts['query']['expires'],
            null,
            $user,
            $this->now,
        ));
    }
}
