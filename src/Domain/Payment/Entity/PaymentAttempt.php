<?php

declare(strict_types=1);

namespace App\Domain\Payment\Entity;

use App\Domain\DomainException;
use App\Domain\Payment\PaymentAttemptStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_attempts')]
#[ORM\UniqueConstraint(name: 'payment_attempts_idempotency_unique', columns: ['idempotency_key'])]
#[ORM\UniqueConstraint(name: 'payment_attempts_method_unique', columns: ['payment_session_id', 'payment_method_hash'])]
#[ORM\Index(name: 'payment_attempts_session_status_idx', columns: ['payment_session_id', 'status'])]
class PaymentAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PaymentSession::class)]
    #[ORM\JoinColumn(name: 'payment_session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PaymentSession $session;

    #[ORM\Column(name: 'payment_method_hash', length: 64, options: ['fixed' => true])]
    private string $paymentMethodHash;

    #[ORM\Column(name: 'idempotency_key', type: Types::GUID)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'payload_encrypted', type: Types::TEXT)]
    private string $payloadEncrypted;

    #[ORM\Column(name: 'receipt_token_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $receiptTokenEncrypted = null;

    #[ORM\Column(name: 'amount_cents', type: Types::INTEGER, nullable: true)]
    private ?int $amountCents;

    #[ORM\Column(length: 3, nullable: true, options: ['fixed' => true])]
    private ?string $currency;

    #[ORM\Column(enumType: PaymentAttemptStatus::class, length: 20, options: ['default' => 'pending'])]
    private PaymentAttemptStatus $status = PaymentAttemptStatus::Pending;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PaymentSession $session,
        string $paymentMethodHash,
        string $idempotencyKey,
        string $payloadEncrypted,
        ?int $amountCents = null,
        ?string $currency = null,
    ) {
        if (null !== $amountCents && $amountCents < 0) {
            throw new DomainException('Payment attempt amount must be >= 0.');
        }
        $this->session = $session;
        $this->paymentMethodHash = $paymentMethodHash;
        $this->idempotencyKey = $idempotencyKey;
        $this->payloadEncrypted = $payloadEncrypted;
        $this->amountCents = $amountCents;
        $this->currency = $currency;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): PaymentSession
    {
        return $this->session;
    }

    public function getStatus(): PaymentAttemptStatus
    {
        return $this->status;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function markProcessing(\DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentAttemptStatus::Processing, $at);
    }

    public function markCompleted(string $receiptTokenEncrypted, \DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentAttemptStatus::Completed, $at);
        $this->receiptTokenEncrypted = $receiptTokenEncrypted;
    }

    public function markFailed(string $error, \DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentAttemptStatus::Failed, $at);
        $this->lastError = $error;
    }

    private function transitionTo(PaymentAttemptStatus $next, \DateTimeImmutable $at): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new DomainException(\sprintf('Payment attempt cannot transition from %s to %s.', $this->status->value, $next->value));
        }
        $this->status = $next;
        $this->updatedAt = $at;
    }
}
