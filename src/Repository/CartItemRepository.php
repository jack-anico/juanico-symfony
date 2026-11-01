<?php

namespace App\Repository;

use App\Entity\CartItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CartItem>
 */
class CartItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CartItem::class);
    }

    /** @return CartItem[] Oldest first, with products loaded to avoid one query per line */
    public function findByOwner(User $user): array
    {
        return $this->createQueryBuilder('ci')
            ->addSelect('p')
            ->join('ci.product', 'p')
            ->andWhere('ci.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('ci.createdAt', 'ASC')
            ->addOrderBy('ci.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Customers whose cart has not changed for at least $hours hours. */
    public function countAbandonedCarts(int $hours = 24): int
    {
        $owners = $this->createQueryBuilder('ci')
            ->select('IDENTITY(ci.owner)')
            ->groupBy('ci.owner')
            ->having('MAX(ci.updatedAt) < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable(sprintf('-%d hours', $hours)))
            ->getQuery()
            ->getSingleColumnResult();

        return count($owners);
    }

    public function findOneByOwnerAndProduct(User $user, Product $product): ?CartItem
    {
        return $this->findOneBy(['owner' => $user, 'product' => $product]);
    }
}
