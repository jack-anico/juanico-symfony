<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\User;
use App\Entity\WishlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WishlistItem>
 */
class WishlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    /** @return WishlistItem[] Newest first, with products loaded */
    public function findByOwner(User $user): array
    {
        return $this->createQueryBuilder('w')
            ->addSelect('p')
            ->join('w.product', 'p')
            ->andWhere('w.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function existsFor(User $user, Product $product): bool
    {
        return $this->count(['owner' => $user, 'product' => $product]) > 0;
    }
}
