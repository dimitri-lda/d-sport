<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Transactional identity mail: verification and password reset. Transport
 * failures are best-effort — logged and swallowed so account flows keep
 * their generic HTTP behavior. Only the mail kind and exception details
 * are logged, never recipients, links or token payloads.
 */
final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
    ) {
    }

    public function sendVerification(User $user, string $verificationUrl): void
    {
        $this->send($user, 'Verify your Sport E email address', 'verify', 'verification', [
            'url' => $verificationUrl,
        ]);
    }

    public function sendPasswordReset(User $user, string $resetUrl): void
    {
        $this->send($user, 'Reset your Sport E password', 'password_reset', 'password_reset', [
            'url' => $resetUrl,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function send(User $user, string $subject, string $template, string $kind, array $context): void
    {
        $email = (new TemplatedEmail())
            ->from($this->fromAddress)
            ->to($user->getEmail())
            ->subject($subject)
            ->htmlTemplate(\sprintf('emails/%s.html.twig', $template))
            ->textTemplate(\sprintf('emails/%s.text.twig', $template))
            ->context($context + ['displayName' => $user->getDisplayName()]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Account mail delivery failed.', [
                'mail' => $kind,
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
