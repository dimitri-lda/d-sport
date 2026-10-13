<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Cart\CartManager;
use App\Application\Cart\CartView;
use App\Application\Commerce\MarketContextResolver;
use App\Domain\Cart\Entity\Cart;
use App\Presentation\Http\ApiProblem;
use App\Presentation\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class CartController
{
    public function __construct(
        private readonly CartManager $carts,
        private readonly CartView $view,
        private readonly MarketContextResolver $markets,
    ) {
    }

    #[Route('/api/v1/cart', name: 'api_v1_cart_show', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        return $this->response($request, $this->carts->current($request));
    }

    #[Route('/api/v1/cart/items', name: 'api_v1_cart_items_add', methods: ['POST'])]
    public function add(Request $request): JsonResponse
    {
        $body = JsonBody::decode($request);
        $fields = [];
        $rawVariant = $body['variant_id'] ?? null;
        $rawQuantity = $body['quantity'] ?? 1;
        $variantId = \is_int($rawVariant) ? $rawVariant : 0;
        $quantity = \is_int($rawQuantity) ? $rawQuantity : 0;
        if ($variantId <= 0) {
            $fields['variant_id'] = ['A valid variant id is required.'];
        }
        if ($quantity < 1 || $quantity > 20) {
            $fields['quantity'] = ['The quantity must be between 1 and 20.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given cart item is invalid.', 422, $fields);
        }

        $cart = $this->carts->add($request, $variantId, $quantity);

        return $this->response($request, $cart, 201);
    }

    #[Route('/api/v1/cart/items/{id}', name: 'api_v1_cart_items_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $body = JsonBody::decode($request);
        $quantity = $body['quantity'] ?? null;
        if (!\is_int($quantity) || $quantity < 0 || $quantity > 20) {
            throw new ApiProblem('validation_failed', 'The given cart item is invalid.', 422, ['quantity' => ['The quantity must be between 0 and 20.']]);
        }

        $cart = $this->carts->update($request, $id, $quantity);

        return $this->response($request, $cart);
    }

    #[Route('/api/v1/cart/items/{id}', name: 'api_v1_cart_items_remove', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function remove(Request $request, int $id): JsonResponse
    {
        $cart = $this->carts->remove($request, $id);

        return $this->response($request, $cart);
    }

    private function response(Request $request, Cart $cart, int $status = 200): JsonResponse
    {
        $market = $this->markets->resolve($request);

        return new JsonResponse(['data' => $this->view->render($cart, $market)], $status);
    }
}
