<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\Category;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /** @return Category[] */
    public function findActiveRoots(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent IS NULL')
            ->andWhere('c.isActive = true')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Category[] */
    public function findChildren(Category $parent): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.parent = :parent')
            ->andWhere('c.isActive = true')
            ->setParameter('parent', $parent)
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Revenue per category from line items, excluding cancelled and refunded orders.
     *
     * @return list<array{id: int, name: string, revenue: string}>
     */
    public function revenueByCategory(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.id', 'c.name', 'SUM(i.quantity * i.unitPrice) AS revenue')
            ->join(Product::class, 'p', 'WITH', 'p.category = c')
            ->join(OrderItem::class, 'i', 'WITH', 'i.product = p')
            ->join('i.order', 'o')
            ->andWhere('o.createdAt BETWEEN :from AND :to')
            ->join('o.payment', 'pay')
            ->andWhere('o.status = :delivered')
            ->andWhere('pay.status = :paid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('delivered', Order::STATUS_DELIVERED)
            ->setParameter('paid', Payment::STATUS_PAID)
            ->groupBy('c.id', 'c.name')
            ->orderBy('revenue', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }
}
