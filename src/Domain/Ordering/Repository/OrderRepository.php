<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Repository;

use App\Domain\Identity\Entity\User;
use App\Domain\Ordering\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Order> */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findByNumber(string $number): ?Order
    {
        return $this->findOneBy(['number' => $number]);
    }

    /** @return list<Order> */
    public function findRecentForUser(User $user): array
    {
        /** @var list<Order> $orders */
        $orders = $this->createQueryBuilder('o')
            ->where('o.user = :user')
            ->orderBy('o.createdAt', 'DESC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        return $orders;
    }
}
