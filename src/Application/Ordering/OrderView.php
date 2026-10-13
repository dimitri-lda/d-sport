<?php

declare(strict_types=1);

namespace App\Application\Ordering;

use App\Domain\Identity\Entity\User;
use App\Infrastructure\Ordering\DbalOrderReadModel;
use App\Presentation\Http\ApiProblem;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

/**
 * Order display with exact ownership semantics: an order is readable when
 * either the `last_order` session marker carries its number (guest checkout,
 * which survives a later login) or the linked user is authenticated.
 * Everything else is a bare 404 — never a 403 — so numbers cannot be probed.
 */
final class OrderView
{
    public function __construct(
        private readonly DbalOrderReadModel $orders,
        private readonly Security $security,
    ) {
    }

    /** @return array<string, mixed> */
    public function byNumberFor(Request $request, string $number): array
    {
        $order = $this->orders->byNumber($number);
        if (null === $order || !$this->owns($request, $order)) {
            throw self::notFound();
        }

        return $order;
    }

    /** @return list<array<string, mixed>> */
    public function forUser(User $user): array
    {
        $id = $user->getId();
        if (null === $id) {
            return [];
        }

        return $this->orders->forUser($id);
    }

    /** @param array<string, mixed> $order */
    private function owns(Request $request, array $order): bool
    {
        $user = $this->security->getUser();
        if ($user instanceof User && $order['user_id'] === $user->getId()) {
            return true;
        }
        if (!$request->hasSession()) {
            return false;
        }
        $last = $request->getSession()->get(PlaceOrder::SESSION_LAST_ORDER);

        return \is_string($last) && '' !== $last && $last === $order['number'];
    }

    private static function notFound(): ApiProblem
    {
        return new ApiProblem('not_found', 'The order was not found.', 404);
    }
}
