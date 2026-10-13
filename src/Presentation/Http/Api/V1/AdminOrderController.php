<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Ordering\AdminOrderTransition;
use App\Domain\Ordering\OrderStatus;
use App\Infrastructure\Ordering\DbalAdminOrderReadModel;
use App\Presentation\Http\ApiProblem;
use App\Presentation\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class AdminOrderController
{
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(
        private readonly DbalAdminOrderReadModel $orders,
        private readonly AdminOrderTransition $transitions,
    ) {
    }

    #[Route('/api/v1/admin/orders', name: 'api_v1_admin_orders_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$query, $status, $from, $to, $page] = self::filters($request);

        $result = $this->orders->page($query, $status, $from, $to, $page);

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => [
                'page' => $result['page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
            ],
            'statuses' => array_map(
                static fn (OrderStatus $status): string => $status->value,
                OrderStatus::cases(),
            ),
        ]);
    }

    #[Route('/api/v1/admin/orders/{id}', name: 'api_v1_admin_orders_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $order = $this->orders->detail($id);
        if (null === $order) {
            throw new ApiProblem('not_found', 'The order was not found.', 404);
        }

        $statusRaw = $order['status'] ?? null;
        $current = \is_string($statusRaw) ? OrderStatus::tryFrom($statusRaw) : null;

        return new JsonResponse(['data' => [
            'order' => $order,
            'next_statuses' => null === $current ? [] : array_map(
                static fn (OrderStatus $status): string => $status->value,
                $current->allowedNextStatuses(),
            ),
        ]]);
    }

    #[Route('/api/v1/admin/orders/{id}/status', name: 'api_v1_admin_orders_status', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function transition(Request $request, int $id): JsonResponse
    {
        $body = JsonBody::decode($request);
        $raw = $body['status'] ?? null;
        $next = \is_string($raw) ? OrderStatus::tryFrom($raw) : null;
        if (null === $next) {
            throw new ApiProblem('validation_failed', 'The given status is invalid.', 422, ['status' => ['A valid order status is required.']]);
        }

        $result = $this->transitions->transition(
            $id,
            $next,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        return new JsonResponse(['data' => $result]);
    }

    /**
     * @return array{?string, ?OrderStatus, ?\DateTimeImmutable, ?\DateTimeImmutable, int}
     */
    private static function filters(Request $request): array
    {
        $fields = [];

        $query = $request->query->getString('q');
        $query = '' === $query ? null : trim($query);
        if (null !== $query && mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $fields['q'] = ['The search query must be at most 100 characters.'];
        }

        $statusRaw = $request->query->getString('status');
        $status = '' === $statusRaw ? null : OrderStatus::tryFrom($statusRaw);
        if ('' !== $statusRaw && null === $status) {
            $fields['status'] = ['The status filter is not a valid order status.'];
        }

        $from = self::day($request->query->getString('from'), 'from', $fields);
        $to = self::day($request->query->getString('to'), 'to', $fields);
        if (null !== $from && null !== $to && $from > $to) {
            $fields['from'] = ['The from date must not be after the to date.'];
        }

        $page = 1;
        $pageRaw = $request->query->getString('page');
        if ('' !== $pageRaw) {
            $page = filter_var($pageRaw, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (false === $page) {
                $fields['page'] = ['The page must be a positive integer.'];
                $page = 1;
            }
        }

        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given order filters are invalid.', 422, $fields);
        }

        return [$query, $status, $from, $to, $page];
    }

    /**
     * Parses a Y-m-d filter into a UTC midnight boundary; failures append a
     * field error and yield null.
     *
     * @param array<string, list<string>> $fields
     */
    private static function day(string $raw, string $field, array &$fields): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            $fields[$field] = ['The '.$field.' date must use the Y-m-d format.'];

            return null;
        }

        return $date;
    }
}
