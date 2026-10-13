<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Cart\CartManager;
use App\Application\Cart\CartView;
use App\Application\Commerce\CountryCatalog;
use App\Application\Commerce\MarketContextResolver;
use App\Domain\Identity\Entity\SavedAddress;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\SavedAddressRepository;
use App\Presentation\Http\Api\ContextView;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Checkout bootstrap: cart, account hints and the shippable country list. */
final class CheckoutController
{
    public function __construct(
        private readonly CartManager $carts,
        private readonly CartView $cartView,
        private readonly MarketContextResolver $markets,
        private readonly CountryCatalog $countries,
        private readonly SavedAddressRepository $addresses,
        private readonly ContextView $contextView,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/v1/checkout', name: 'api_v1_checkout_show', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $market = $this->markets->resolve($request);
        $cart = $this->carts->current($request);

        $address = null;
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $address = self::address($this->addresses->findForUser($user));
        }

        return new JsonResponse(['data' => [
            'cart' => $this->cartView->render($cart, $market),
            'user' => $this->contextView->user(),
            'address' => $address,
            'countries' => $this->countries->countries($market->locale),
        ]]);
    }

    /** @return array<string, mixed>|null */
    private static function address(?SavedAddress $address): ?array
    {
        if (null === $address) {
            return null;
        }

        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'line1' => $address->getLine1(),
            'line2' => $address->getLine2(),
            'city' => $address->getCity(),
            'postal_code' => $address->getPostalCode(),
            'country' => $address->getCountry(),
        ];
    }
}
