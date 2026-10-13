<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repository;

use App\Domain\Identity\Entity\SavedAddress;
use App\Domain\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SavedAddress> */
class SavedAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedAddress::class);
    }

    /** Each user holds at most one saved address. */
    public function findForUser(User $user): ?SavedAddress
    {
        return $this->findOneBy(['user' => $user]);
    }
}
