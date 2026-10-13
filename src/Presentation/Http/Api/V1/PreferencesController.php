<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Commerce\CountryCatalog;
use App\Application\Commerce\MarketContextResolver;
use App\Presentation\Http\Api\ContextView;
use App\Presentation\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PreferencesController
{
    public function __construct(
        private readonly MarketContextResolver $resolver,
        private readonly CountryCatalog $countries,
        private readonly ContextView $contextView,
    ) {
    }

    #[Route('/api/v1/preferences', name: 'api_v1_preferences', methods: ['PUT'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        $country = \is_array($body) && \is_string($body['country'] ?? null) ? $body['country'] : null;
        $locale = \is_array($body) && \is_string($body['locale'] ?? null) ? $body['locale'] : null;

        $fields = [];
        if (null === $country || '' === $country) {
            $fields['country'] = ['The country field is required.'];
        } elseif (!$this->countries->isSupported($country)) {
            $fields['country'] = ['The selected country is not supported.'];
        }
        if (null === $locale || '' === $locale) {
            $fields['locale'] = ['The locale field is required.'];
        } elseif (!$this->countries->isSupportedLocale($locale)) {
            $fields['locale'] = ['The selected locale is not supported.'];
        }
        if ([] !== $fields) {
            throw new ApiProblem('validation_failed', 'The given preferences are invalid.', 422, $fields);
        }

        $market = $this->resolver->applyPreferences($request->getSession(), (string) $country, (string) $locale);

        return new JsonResponse(['data' => $this->contextView->forMarket($market)]);
    }
}
