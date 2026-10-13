<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog;

use App\Application\Catalog\RefreshCatalogCache;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Refills one catalog entry from the source read model. Permanently
 * invalid messages (unknown operation, malformed arguments) are rejected
 * without a retry; transient store/database failures bubble up so
 * Messenger retries — the refresh is a pure replace, so it is safe to
 * redeliver.
 */
#[AsMessageHandler]
final class RefreshCatalogCacheHandler
{
    public function __construct(
        private readonly CachedStorefrontCatalog $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshCatalogCache $message): void
    {
        try {
            $this->catalog->refresh($message);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning('Rejected a catalog cache refresh message.', [
                'operation' => $message->operation,
                'exception' => $exception::class,
            ]);
        }
    }
}
