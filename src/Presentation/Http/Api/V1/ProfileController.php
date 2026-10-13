<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\V1;

use App\Application\Identity\AddressInput;
use App\Application\Identity\ProfileService;
use App\Domain\Identity\Entity\SavedAddress;
use App\Domain\Identity\Entity\User;
use App\Domain\Identity\Repository\SavedAddressRepository;
use App\Presentation\Http\Api\ContextView;
use App\Presentation\Http\JsonBody;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ProfileController
{
    public function __construct(
        private readonly ProfileService $profile,
        private readonly SavedAddressRepository $addresses,
        private readonly ContextView $contextView,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route('/api/v1/profile', name: 'api_v1_profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'data' => [
                'user' => $this->contextView->user(),
                'address' => self::addressView($this->addresses->findForUser($user)),
            ],
        ]);
    }

    #[Route('/api/v1/profile', name: 'api_v1_profile_update', methods: ['PATCH'])]
    public function update(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = JsonBody::decode($request);
        $this->profile->updateProfile(
            $user,
            JsonBody::string($body, 'display_name') ?? '',
            JsonBody::string($body, 'email') ?? '',
            self::now(),
        );

        return new JsonResponse(['data' => ['user' => $this->contextView->user()]]);
    }

    #[Route('/api/v1/profile/address', name: 'api_v1_profile_address', methods: ['PUT'])]
    public function address(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = JsonBody::decode($request);
        $line2 = JsonBody::string($body, 'line2');
        $address = $this->profile->updateAddress($user, new AddressInput(
            JsonBody::string($body, 'first_name') ?? '',
            JsonBody::string($body, 'last_name') ?? '',
            JsonBody::string($body, 'line1') ?? '',
            null !== $line2 && '' !== trim($line2) ? trim($line2) : null,
            JsonBody::string($body, 'city') ?? '',
            JsonBody::string($body, 'postal_code') ?? '',
            JsonBody::string($body, 'country') ?? '',
        ));

        return new JsonResponse(['data' => ['address' => self::addressView($address)]]);
    }

    #[Route('/api/v1/profile/password', name: 'api_v1_profile_password', methods: ['PUT'])]
    public function password(Request $request, #[CurrentUser] User $user): Response
    {
        $body = JsonBody::decode($request);
        $this->profile->changePassword(
            $user,
            JsonBody::string($body, 'current_password') ?? '',
            JsonBody::string($body, 'password') ?? '',
            JsonBody::string($body, 'password_confirmation') ?? '',
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/profile', name: 'api_v1_profile_delete', methods: ['DELETE'])]
    public function delete(Request $request, #[CurrentUser] User $user): Response
    {
        $password = JsonBody::string(JsonBody::decode($request), 'password') ?? '';
        $this->profile->deleteAccount($user, $password);
        // The account row is gone. Clear the token so ContextListener cannot
        // re-serialize the removed entity (whose identity is reset on flush),
        // then drop the session so the cookie is dead too.
        $this->tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, string|null>|null */
    private static function addressView(?SavedAddress $address): ?array
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

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
