<?php

declare(strict_types=1);

namespace App\Domain\Payment\Entity;

use App\Domain\DomainException;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Payment\PaymentProvider;
use App\Domain\Payment\PaymentSessionStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_sessions')]
#[ORM\UniqueConstraint(name: 'payment_sessions_order_unique', columns: ['order_id'])]
#[ORM\UniqueConstraint(name: 'payment_sessions_source_unique', columns: ['source_identifier'])]
#[ORM\UniqueConstraint(name: 'payment_sessions_token_hash_unique', columns: ['session_token_hash'])]
#[ORM\Index(name: 'payment_sessions_status_idx', columns: ['status'], options: ['where' => "((status)::text <> 'paid'::text)"])]
class PaymentSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(enumType: PaymentProvider::class, length: 20, options: ['default' => 'shop_pay'])]
    private PaymentProvider $provider;

    #[ORM\Column(name: 'source_identifier', length: 120)]
    private string $sourceIdentifier;

    #[ORM\Column(name: 'session_token_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $sessionTokenEncrypted = null;

    #[ORM\Column(name: 'session_token_hash', length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $sessionTokenHash = null;

    #[ORM\Column(name: 'checkout_url', type: Types::TEXT, nullable: true)]
    private ?string $checkoutUrl = null;

    #[ORM\Column(name: 'receipt_token_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $receiptTokenEncrypted = null;

    #[ORM\Column(enumType: PaymentSessionStatus::class, length: 20, options: ['default' => 'pending'])]
    private PaymentSessionStatus $status = PaymentSessionStatus::Pending;

    #[ORM\Column(name: 'provider_order_id', length: 120, nullable: true)]
    private ?string $providerOrderId = null;

    #[ORM\Column(name: 'last_error', type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Order $order, string $sourceIdentifier, PaymentProvider $provider = PaymentProvider::ShopPay)
    {
        $this->order = $order;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->provider = $provider;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getProvider(): PaymentProvider
    {
        return $this->provider;
    }

    public function getStatus(): PaymentSessionStatus
    {
        return $this->status;
    }

    public function getSourceIdentifier(): string
    {
        return $this->sourceIdentifier;
    }

    public function getSessionTokenHash(): ?string
    {
        return $this->sessionTokenHash;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    /** Attaches the provider session material and moves to `ready`. */
    public function markReady(string $sessionTokenEncrypted, string $sessionTokenHash, string $checkoutUrl, \DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentSessionStatus::Ready, $at);
        $this->sessionTokenEncrypted = $sessionTokenEncrypted;
        $this->sessionTokenHash = $sessionTokenHash;
        $this->checkoutUrl = $checkoutUrl;
    }

    public function markProcessing(string $providerOrderId, \DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentSessionStatus::Processing, $at);
        $this->providerOrderId = $providerOrderId;
    }

    public function markPaid(string $receiptTokenEncrypted, \DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentSessionStatus::Paid, $at);
        $this->receiptTokenEncrypted = $receiptTokenEncrypted;
        $this->paidAt = $at;
    }

    public function markFailed(string $error, \DateTimeImmutable $at): void
    {
        $this->transitionTo(PaymentSessionStatus::Failed, $at);
        $this->lastError = $error;
    }

    private function transitionTo(PaymentSessionStatus $next, \DateTimeImmutable $at): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new DomainException(\sprintf('Payment session %s cannot transition from %s to %s.', $this->sourceIdentifier, $this->status->value, $next->value));
        }
        $this->status = $next;
        $this->updatedAt = $at;
    }
}
