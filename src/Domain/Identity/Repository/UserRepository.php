<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repository;

use App\Domain\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** Case-insensitive lookup matching the functional lower(email) index. */
    public function findByEmail(string $email): ?User
    {
        $user = $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();

        return $user instanceof User ? $user : null;
    }
}
