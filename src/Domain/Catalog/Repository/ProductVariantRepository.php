<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Repository;

use App\Domain\Catalog\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProductVariant> */
class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /** A variant is only sellable while its product stays active. */
    public function findSellable(int $id): ?ProductVariant
    {
        /** @var ?ProductVariant $variant */
        $variant = $this->createQueryBuilder('v')
            ->innerJoin('v.product', 'p')
            ->where('v.id = :id')
            ->andWhere('p.active = true')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $variant;
    }
}
