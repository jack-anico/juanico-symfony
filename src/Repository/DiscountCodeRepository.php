<?php

namespace App\Repository;

use App\Entity\DiscountCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DiscountCode>
 */
class DiscountCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DiscountCode::class);
    }

    /** Null unless the code is active, unexpired, under its usage limit and the subtotal meets its minimum. */
    public function findValidByCode(string $code, string $subtotal): ?DiscountCode
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.code = :code')
            ->andWhere('d.isActive = true')
            ->andWhere('d.expiresAt IS NULL OR d.expiresAt > :now')
            ->andWhere('d.usageLimit IS NULL OR d.timesUsed < d.usageLimit')
            ->andWhere('d.minSpend IS NULL OR d.minSpend <= :subtotal')
            ->setParameter('code', $code)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('subtotal', $subtotal)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
