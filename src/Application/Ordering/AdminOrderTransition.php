<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Application\Catalog\CatalogCacheInvalidator;
use App\Domain\Ordering\Entity\Order;
use App\Domain\Ordering\OrderStatus;
use App\Presentation\Http\ApiProblem;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Admin status transitions. One transaction: the fresh order row is locked
 * pessimistically, the transition validated, inventory released exactly once
 * when the goods never left the warehouse (cancellation, or a refund from
 * paid), then status and stock flush together.
 */
final class AdminOrderTransition
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InventoryRelease $inventoryRelease,
        private readonly CatalogCacheInvalidator $catalogCache,
    ) {
    }

    /** @return array{status: string, next_statuses: list<string>} */
    public function transition(int $orderId, OrderStatus $next, \DateTimeImmutable $at): array
    {
        $result = $this->entityManager->wrapInTransaction(
            function (EntityManagerInterface $em) use ($orderId, $next, $at): array {
                $order = $em->find(Order::class, $orderId);
                if (!$order instanceof Order) {
                    throw new ApiProblem('not_found', 'The order was not found.', 404);
                }
                // Lock the order row alone: find(PESSIMISTIC_WRITE) also
                // locks the outer-joined nullable user relation, which
                // PostgreSQL rejects. Refresh re-reads under the lock.
                $em->lock($order, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($order);
                if (!$order->getStatus()->canTransitionTo($next)) {
                    throw new ApiProblem('invalid_status_transition', 'The order cannot move to that status.', 422, ['status' => ['The requested status transition is not allowed.']]);
                }
                // Release stock only when the goods never left the
                // warehouse: cancellation pre-fulfilment, or a refund
                // straight from paid. Shipped/delivered refunds keep it.
                $released = OrderStatus::Cancelled === $next
                    || (OrderStatus::Refunded === $next && OrderStatus::Paid === $order->getStatus());
                if ($released) {
                    $this->inventoryRelease->releaseLocked($em, $order, $at);
                }
                $order->transitionTo($next, $at);
                $em->flush();

                return ['view' => self::view($order), 'released' => $released];
            },
        );

        if ($result['released']) {
            // The release committed with the transition; invalidate after
            // the commit, never inside — a rolled-back attempt must not fire.
            $this->catalogCache->productsChanged();
        }

        return $result['view'];
    }

    /** @return array{status: string, next_statuses: list<string>} */
    public static function view(Order $order): array
    {
        return [
            'status' => $order->getStatus()->value,
            'next_statuses' => array_map(
                static fn (OrderStatus $status): string => $status->value,
                $order->getStatus()->allowedNextStatuses(),
            ),
        ];
    }
}
