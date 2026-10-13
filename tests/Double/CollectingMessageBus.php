<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * In-memory message bus for unit tests: records dispatched messages and
 * answers each dispatch immediately with its envelope.
 */
final class CollectingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    /** @param list<\Symfony\Component\Messenger\Stamp\StampInterface> $stamps */
    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
