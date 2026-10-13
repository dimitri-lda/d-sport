<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api;

use App\Application\Commerce\CountryCatalog;
use App\Application\Commerce\MarketContextResolver;
use App\Domain\Commerce\MarketContext;
use App\Domain\Identity\Entity\User;
use App\EventSubscriber\ApiCsrfSubscriber;
use App\Presentation\Http\ApiProblem;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Builds the shared storefront context payload (user, market, supported
 * countries/locales and the API CSRF token) for the /context and /preferences
 * endpoints. Single place so both routes can never drift apart.
 */
final class ContextView
{
    public function __construct(
        private readonly MarketContextResolver $resolver,
        private readonly CountryCatalog $countries,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function current(Request $request): array
    {
        return $this->payload($this->resolver->resolve($request));
    }

    /** @return array<string, mixed> */
    public function forMarket(MarketContext $market): array
    {
        return $this->payload($market);
    }

    /** @return array<string, mixed> */
    private function payload(MarketContext $market): array
    {
        return [
            'user' => $this->user(),
            'market' => [
                'country' => $market->country,
                'code' => $market->market,
                'currency' => $market->currency,
                'locale' => $market->locale,
                'vat_basis_points' => $market->vatBasisPoints,
                'exchange_rate' => $market->exchangeRate,
            ],
            'countries' => $this->countries->countries($market->locale),
            'locales' => $this->countries->locales(),
            'csrf_token' => $this->csrfTokenManager->getToken(ApiCsrfSubscriber::TOKEN_ID)->getValue(),
        ];
    }

    /** @return array{id: int, email: string, display_name: string, role: string, email_verified: bool}|null */
    public function user(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $id = $user->getId();
        if (null === $id) {
            throw new ApiProblem('session_user_invalid', 'The authenticated user has no id.', 500);
        }

        return [
            'id' => $id,
            'email' => $user->getEmail(),
            'display_name' => $user->getDisplayName(),
            'role' => $user->getRole()->value,
            'email_verified' => null !== $user->getEmailVerifiedAt(),
        ];
    }
}
