<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Identity\VerificationLinkSigner;
use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * End-to-end identity flows against the per-run isolated test database.
 * Requires Docker postgres+redis (see scripts/backend-test.sh).
 */
final class IdentityFlowTest extends WebTestCase
{
    private const PASSWORD = 'Sport-E!2026';

    protected function setUp(): void
    {
        if ('1' !== getenv('RUN_INFRASTRUCTURE_TESTS')) {
            self::markTestSkipped('Use make test to run against isolated PostgreSQL and Redis.');
        }
    }

    private static function csrf(KernelBrowser $client): string
    {
        $client->request('GET', '/api/v1/context');
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $token = $payload['data']['csrf_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private static function sessionId(KernelBrowser $client): ?string
    {
        foreach (['MOCKSESSID', 'PHPSESSID'] as $name) {
            $cookie = $client->getCookieJar()->get($name);
            if (null !== $cookie) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    /** @param array<string, mixed> $body */
    private static function jsonRequest(
        KernelBrowser $client,
        string $method,
        string $uri,
        array $body = [],
        ?string $csrf = null,
    ): void {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $csrf) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrf;
        }
        $client->request($method, $uri, [], [], $server, (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private static function payload(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        return $em;
    }

    private static function register(KernelBrowser $client, string $email): void
    {
        $csrf = self::csrf($client);
        self::jsonRequest($client, 'POST', '/api/v1/auth/register', [
            'display_name' => 'Flow User',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accepted_terms' => true,
        ], $csrf);
        self::assertResponseStatusCodeSame(201);
    }

    private static function login(KernelBrowser $client, string $email, string $password): void
    {
        self::jsonRequest($client, 'POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ], self::csrf($client));
    }

    private static function userByEmail(string $email): User
    {
        $users = self::em()->getRepository(User::class);
        \assert($users instanceof UserRepository);
        $user = $users->findByEmail($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    public function testRegisterCreatesUserAcceptsTermsAndRotatesSession(): void
    {
        $client = self::createClient();
        $email = uniqid('reg', true).'@example.test';
        self::csrf($client);
        $sessionBefore = self::sessionId($client);
        self::assertNotNull($sessionBefore, 'The context request should have started a session.');

        self::register($client, $email);
        $data = self::payload($client)['data'];
        self::assertSame($email, $data['user']['email']);
        self::assertFalse($data['user']['email_verified']);
        self::assertIsString($data['csrf_token']);

        $sessionAfter = self::sessionId($client);
        self::assertNotNull($sessionAfter);
        self::assertNotSame($sessionBefore, $sessionAfter, 'Registration must rotate the session id.');

        $user = self::userByEmail($email);
        self::assertTrue($user->hasAcceptedTerms());
        self::assertNotNull($user->getTermsAcceptedAt());
    }

    public function testDuplicateEmailIsRejectedCaseInsensitively(): void
    {
        $client = self::createClient();
        $email = uniqid('dup', true).'@example.test';
        self::register($client, $email);

        $csrf = self::csrf($client);
        self::jsonRequest($client, 'POST', '/api/v1/auth/register', [
            'display_name' => 'Other',
            'email' => strtoupper($email),
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accepted_terms' => true,
        ], $csrf);

        self::assertResponseStatusCodeSame(422);
        $payload = self::payload($client);
        self::assertSame('email_taken', $payload['error']['code']);
        self::assertArrayHasKey('email', $payload['error']['fields']);
    }

    public function testRegisterWithoutCsrfIsRejected(): void
    {
        $client = self::createClient();
        self::jsonRequest($client, 'POST', '/api/v1/auth/register', [
            'display_name' => 'No Token',
            'email' => uniqid('csrf', true).'@example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accepted_terms' => true,
        ]);

        self::assertResponseStatusCodeSame(419);
        self::assertSame('csrf_invalid', self::payload($client)['error']['code']);
    }

    public function testLoginLogoutAndSessionRotation(): void
    {
        $client = self::createClient();
        $email = uniqid('login', true).'@example.test';
        self::register($client, $email);

        // Logout returns 204 and kills the session.
        $csrf = self::payload($client)['data']['csrf_token'];
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $csrf);
        self::assertResponseStatusCodeSame(204);

        // Wrong credentials -> uniform 401 envelope.
        self::login($client, $email, 'Wrong-Pass1!');
        self::assertResponseStatusCodeSame(401);
        $error = self::payload($client)['error'];
        self::assertSame('invalid_credentials', $error['code']);

        // Right credentials -> 200 context and a fresh session id.
        $before = self::sessionId($client);
        self::login($client, $email, self::PASSWORD);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($email, self::payload($client)['data']['user']['email']);
        self::assertNotSame($before, self::sessionId($client), 'Login must rotate the session id.');
    }

    public function testLoginCsrfTokenStaysValidAfterSessionFixationClear(): void
    {
        $client = self::createClient();
        $email = uniqid('csrfrot', true).'@example.test';
        self::register($client, $email);
        $csrf = self::payload($client)['data']['csrf_token'];
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $csrf);
        self::assertResponseStatusCodeSame(204);

        self::login($client, $email, self::PASSWORD);
        self::assertResponseStatusCodeSame(200);
        $loginToken = self::payload($client)['data']['csrf_token'];

        // The fixation strategy clears session CSRF tokens after the success
        // handler runs; the advertised token must match the refreshed value.
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $loginToken);
        self::assertResponseStatusCodeSame(204);
    }

    public function testLoginIsCaseInsensitive(): void
    {
        $client = self::createClient();
        $email = uniqid('case', true).'@example.test';
        self::register($client, $email);
        $csrf = self::payload($client)['data']['csrf_token'];
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $csrf);
        self::assertResponseStatusCodeSame(204);

        // Upper-cased identifier must resolve through the lower(email) index.
        self::login($client, strtoupper($email), self::PASSWORD);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($email, self::payload($client)['data']['user']['email']);
    }

    public function testThrottledLoginsKeepTheSameEnvelope(): void
    {
        $client = self::createClient();
        $email = uniqid('throttle', true).'@example.test';
        self::register($client, $email);
        $csrf = self::payload($client)['data']['csrf_token'];
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $csrf);

        for ($i = 0; $i < 7; ++$i) {
            self::login($client, $email, 'Wrong-Pass1!');
            self::assertResponseStatusCodeSame(401);
            self::assertSame('invalid_credentials', self::payload($client)['error']['code']);
        }
    }

    public function testForgotPasswordIsGenericForKnownAndUnknownEmails(): void
    {
        $client = self::createClient();
        $known = uniqid('known', true).'@example.test';
        self::register($client, $known);
        $csrf = self::payload($client)['data']['csrf_token'];
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $csrf);

        foreach ([uniqid('ghost', true).'@example.test', $known] as $email) {
            self::jsonRequest($client, 'POST', '/api/v1/auth/forgot-password', [
                'email' => $email,
            ], self::csrf($client));
            self::assertResponseStatusCodeSame(202);
            self::assertSame(
                'password_reset_if_known',
                self::payload($client)['data']['message'],
                'Forgot must never reveal whether the account exists.',
            );
        }
    }

    public function testResetPasswordStoresNoPlaintextAndUpdatesPassword(): void
    {
        $client = self::createClient();
        $email = uniqid('reset', true).'@example.test';
        self::register($client, $email);
        $csrf = self::payload($client)['data']['csrf_token'];
        self::jsonRequest($client, 'POST', '/api/v1/auth/logout', [], $csrf);

        $em = self::em();
        $user = self::userByEmail($email);
        $em->persist(new PasswordResetToken(
            $user,
            hash('sha256', 'functional-raw-token'),
            new \DateTimeImmutable('+1 hour', new \DateTimeZone('UTC')),
        ));
        $em->flush();

        // Wrong token -> fielded 422, no plaintext anywhere.
        self::jsonRequest($client, 'POST', '/api/v1/auth/reset-password', [
            'email' => $email,
            'token' => 'wrong-token',
            'password' => 'New-Pass!2026',
            'password_confirmation' => 'New-Pass!2026',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('password_reset_invalid', self::payload($client)['error']['code']);

        self::jsonRequest($client, 'POST', '/api/v1/auth/reset-password', [
            'email' => $email,
            'token' => 'functional-raw-token',
            'password' => 'New-Pass!2026',
            'password_confirmation' => 'New-Pass!2026',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(204);

        // The old password no longer authenticates; the new one does.
        self::login($client, $email, self::PASSWORD);
        self::assertResponseStatusCodeSame(401);
        self::login($client, $email, 'New-Pass!2026');
        self::assertResponseStatusCodeSame(200);
    }

    public function testVerificationLinkVerifiesAndRejectsTampering(): void
    {
        $client = self::createClient();
        $email = uniqid('verify', true).'@example.test';
        self::register($client, $email);

        $signer = self::getContainer()->get(VerificationLinkSigner::class);
        \assert($signer instanceof VerificationLinkSigner);
        $user = self::userByEmail($email);
        $url = $signer->generate($user, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $parts = parse_url($url);
        self::assertIsArray($parts);
        $path = $parts['path'].'?'.$parts['query'];

        // Tampered signature -> 403 problem envelope.
        parse_str($parts['query'] ?? '', $query);
        $badQuery = 'expires='.$query['expires'].'&signature='.str_repeat('0', 64);
        $client->request('GET', $parts['path'].'?'.$badQuery);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('verification_link_invalid', self::payload($client)['error']['code']);

        // Real link -> 302 into the SPA and the user is now verified.
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(302);
        self::assertSame('/verify-email?verified=1', $client->getResponse()->headers->get('Location'));
        self::assertTrue(self::userByEmail($email)->isEmailVerified());
    }

    public function testUnauthenticatedRequestsGetJson401NotRedirect(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/profile');
        self::assertResponseStatusCodeSame(401);
        self::assertStringContainsString('json', (string) $client->getResponse()->headers->get('content-type'));
    }

    public function testResendVerificationIsThrottled(): void
    {
        $client = self::createClient();
        $email = uniqid('resend', true).'@example.test';
        self::register($client, $email);

        // Limiter keys live in shared Redis, while user ids restart per test
        // DB run — reset this user's window for a deterministic count.
        $limiter = self::getContainer()->get('limiter.email_verification');
        \assert($limiter instanceof RateLimiterFactory);
        $limiter->create((string) self::userByEmail($email)->getId())->reset();

        for ($i = 0; $i < 6; ++$i) {
            self::jsonRequest($client, 'POST', '/api/v1/auth/email/verification-notification', [], self::csrf($client));
            self::assertResponseStatusCodeSame(202);
            self::assertSame('verification_link_sent', self::payload($client)['data']['message']);
        }
        self::jsonRequest($client, 'POST', '/api/v1/auth/email/verification-notification', [], self::csrf($client));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_requests', self::payload($client)['error']['code']);
    }

    public function testConfirmPasswordMarkerLifecycle(): void
    {
        $client = self::createClient();
        $email = uniqid('confirm', true).'@example.test';
        self::register($client, $email);

        $client->request('GET', '/api/v1/auth/confirm-password');
        self::assertResponseStatusCodeSame(200);
        $status = self::payload($client)['data'];
        self::assertFalse($status['confirmed']);
        self::assertNull($status['confirmed_at']);

        self::jsonRequest($client, 'POST', '/api/v1/auth/confirm-password', [
            'password' => 'Wrong-Pass1!',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('password', self::payload($client)['error']['fields']);

        self::jsonRequest($client, 'POST', '/api/v1/auth/confirm-password', [
            'password' => self::PASSWORD,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/v1/auth/confirm-password');
        $status = self::payload($client)['data'];
        self::assertTrue($status['confirmed']);
        self::assertIsInt($status['confirmed_at']);
    }

    public function testProfileLifecycle(): void
    {
        $client = self::createClient();
        $email = uniqid('profile', true).'@example.test';
        self::register($client, $email);

        // Read: user + empty address.
        $client->request('GET', '/api/v1/profile');
        self::assertResponseStatusCodeSame(200);
        $data = self::payload($client)['data'];
        self::assertSame($email, $data['user']['email']);
        self::assertNull($data['address']);

        // Update display name.
        self::jsonRequest($client, 'PATCH', '/api/v1/profile', [
            'display_name' => 'Renamed User',
            'email' => $email,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Renamed User', self::payload($client)['data']['user']['display_name']);

        // Upsert address; country is normalized to uppercase.
        self::jsonRequest($client, 'PUT', '/api/v1/profile/address', [
            'first_name' => 'Flow',
            'last_name' => 'User',
            'line1' => 'Main Street 1',
            'line2' => null,
            'city' => 'Berlin',
            'postal_code' => '10115',
            'country' => 'de',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('DE', self::payload($client)['data']['address']['country']);

        // Password change requires the current password.
        self::jsonRequest($client, 'PUT', '/api/v1/profile/password', [
            'current_password' => 'Wrong-Pass1!',
            'password' => 'New-Pass!2026',
            'password_confirmation' => 'New-Pass!2026',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('current_password', self::payload($client)['error']['fields']);

        self::jsonRequest($client, 'PUT', '/api/v1/profile/password', [
            'current_password' => self::PASSWORD,
            'password' => 'New-Pass!2026',
            'password_confirmation' => 'New-Pass!2026',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(204);

        // Delete requires the (new) current password, then the session dies.
        self::jsonRequest($client, 'DELETE', '/api/v1/profile', [
            'password' => self::PASSWORD,
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(422);

        self::jsonRequest($client, 'DELETE', '/api/v1/profile', [
            'password' => 'New-Pass!2026',
        ], self::csrf($client));
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/v1/profile');
        self::assertResponseStatusCodeSame(401);
        self::assertNull(self::em()->getRepository(User::class)->findByEmail($email));
    }
}
