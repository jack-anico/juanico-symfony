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

    public function findOneByOwnerAndProduct(User $user, Product $product): ?CartItem
    {
        return $this->findOneBy(['owner' => $user, 'product' => $product]);
    }
}
