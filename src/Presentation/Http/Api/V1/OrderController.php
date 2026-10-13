<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Ordering\OrderView;
use App\Application\Ordering\PlaceOrder;
use App\Application\Ordering\PlaceOrderInput;
use App\Domain\Identity\Entity\User;
use App\Presentation\Http\ApiProblem;
use App\Presentation\Http\JsonBody;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class OrderController
{
    public function __construct(
        private readonly PlaceOrder $placeOrder,
        private readonly OrderView $orderView,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/v1/orders', name: 'api_v1_orders_place', methods: ['POST'])]
    public function place(Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $input = new PlaceOrderInput(
            JsonBody::string($body, 'email') ?? '',
            JsonBody::string($body, 'phone') ?? '',
            JsonBody::string($body, 'first_name') ?? '',
            JsonBody::string($body, 'last_name') ?? '',
            JsonBody::string($body, 'line1') ?? '',
            JsonBody::string($body, 'line2'),
            JsonBody::string($body, 'city') ?? '',
            JsonBody::string($body, 'postal_code') ?? '',
            JsonBody::string($body, 'country') ?? '',
            JsonBody::string($body, 'delivery_method') ?? '',
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $order = $this->placeOrder->place($request, $input, $now);

        return new JsonResponse(['data' => [
            'id' => $order->getId(),
            'number' => $order->getNumber(),
        ]], 201);
    }

    #[Route('/api/v1/orders/{number}', name: 'api_v1_orders_show', requirements: ['number' => 'SP-[A-F0-9]{8}'], methods: ['GET'])]
    public function show(Request $request, string $number): JsonResponse
    {
        return new JsonResponse(['data' => $this->orderView->byNumberFor($request, $number)]);
    }

    #[Route('/api/v1/me/orders', name: 'api_v1_me_orders', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function mine(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new ApiProblem('unauthorized', 'Authentication is required.', 401);
        }
        if (null === $user->getEmailVerifiedAt()) {
            throw new ApiProblem('email_unverified', 'The email address must be verified to list orders.', 403);
        }

        return new JsonResponse(['data' => $this->orderView->forUser($user)]);
    }
}
