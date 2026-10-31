<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Review;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Review>
 */
class ReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Review::class);
    }

    /** @return Review[] */
    public function findApprovedForProduct(Product $product): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('a')
            ->join('r.author', 'a')
            ->andWhere('r.product = :product')
            ->andWhere('r.isApproved = true')
            ->setParameter('product', $product)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @return Review[] Awaiting moderation, oldest first */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.isApproved = false')
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return float|null Average of approved ratings, or null with none */
    public function averageRating(Product $product): ?float
    {
        $average = $this->createQueryBuilder('r')
            ->select('AVG(r.rating)')
            ->andWhere('r.product = :product')
            ->andWhere('r.isApproved = true')
            ->setParameter('product', $product)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $average ? null : (float) $average;
    }
}
