<?php

declare(strict_types=1);

namespace App\Domain\Cart\Repository;

use App\Domain\Cart\Entity\Cart;
use App\Domain\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Cart> */
class CartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cart::class);
    }

    public function findForUser(User $user): ?Cart
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function findForGuestToken(string $token): ?Cart
    {
        return $this->findOneBy(['guestToken' => $token]);
    }
}
