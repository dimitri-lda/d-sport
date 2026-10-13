<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Catalog\CatalogCacheInvalidator;
use App\Domain\Catalog\Entity\ProductVariant;
use App\Domain\DomainException;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Ordering\Entity\OrderItem;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Returns reserved stock for an order's items. Idempotent — a released order
 * is a no-op — and safe under concurrency: the order row itself is locked
 * pessimistically before the released flag is read, so two racing releases
 * serialize on the row and only the first restores stock. Variants are then
 * locked in stable id order.
 */
final class InventoryRelease
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CatalogCacheInvalidator $catalogCache,
    ) {
    }

    public function release(Order $order, \DateTimeImmutable $at): void
    {
        $id = $order->getId();
        if (null === $id) {
            throw new DomainException('Cannot release inventory for an unpersisted order.');
        }

        $this->entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($id, $at): void {
            $fresh = $em->find(Order::class, $id);
            if (!$fresh instanceof Order) {
                return;
            }
            // Lock the order row itself — find(PESSIMISTIC_WRITE) would also
            // lock the outer-joined nullable user relation, which PostgreSQL
            // forbids. Refresh re-reads the row under the held lock, so a
            // stale identity-map copy can never veto or double-restore.
            $em->lock($fresh, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($fresh);
            $this->releaseLocked($em, $fresh, $at);
            $em->flush();
        });

        // Stock changed only if the transaction committed; invalidate now,
        // never inside the transaction (a rollback would have fired it too).
        $this->catalogCache->productsChanged();
    }

    /**
     * Release for an order already locked in the caller's transaction —
     * used by status transitions that must not nest a second transaction.
     */
    public function releaseLocked(EntityManagerInterface $em, Order $order, \DateTimeImmutable $at): void
    {
        if ($order->isInventoryReleased()) {
            return;
        }

        $items = $order->getItems()->toArray();
        usort($items, static function (OrderItem $a, OrderItem $b): int {
            return ($a->getVariant()?->getId() ?? 0) <=> ($b->getVariant()?->getId() ?? 0);
        });
        foreach ($items as $item) {
            $variant = $item->getVariant();
            if (!$variant instanceof ProductVariant) {
                continue; // variant deleted since ordering: nothing to restore
            }
            $em->lock($variant, LockMode::PESSIMISTIC_WRITE);
            $variant->release($item->getQuantity());
        }
        $order->releaseInventory($at);
    }
}
