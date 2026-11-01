<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderItem>
 */
class OrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    /** True when the user has received this product, which is what allows them to review it. */
    public function hasReceived(User $user, Product $product): bool
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->join('i.order', 'o')
            ->andWhere('i.product = :product')
            ->andWhere('o.customer = :user')
            ->andWhere('o.status = :delivered')
            ->setParameter('product', $product)
            ->setParameter('user', $user)
            ->setParameter('delivered', Order::STATUS_DELIVERED)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
