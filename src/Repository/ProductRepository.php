<?php

namespace App\Repository;

use Doctrine\DBAL\LockMode;
use App\Entity\Payment;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Storefront search. Returns the query so controllers can paginate it.
     * A category filter also matches products in its direct child categories.
     */
    public function search(
        ?string $q = null,
        ?int $categoryId = null,
        ?int $brandId = null,
        ?string $minPrice = null,
        ?string $maxPrice = null,
        ?string $sort = null,
    ): Query {
        $qb = $this->activeProducts()
            ->addSelect('COALESCE(p.salePrice, p.price) AS HIDDEN effectivePrice')
            ->join('p.category', 'c');

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('p.name LIKE :q OR p.sku LIKE :q OR p.description LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($q), '%_\\').'%');
        }
        if (null !== $categoryId) {
            $qb->andWhere('c.id = :category OR IDENTITY(c.parent) = :category')
                ->setParameter('category', $categoryId);
        }
        if (null !== $brandId) {
            $qb->andWhere('IDENTITY(p.brand) = :brand')->setParameter('brand', $brandId);
        }
        if (null !== $minPrice) {
            $qb->andWhere('COALESCE(p.salePrice, p.price) >= :minPrice')->setParameter('minPrice', $minPrice);
        }
        if (null !== $maxPrice) {
            $qb->andWhere('COALESCE(p.salePrice, p.price) <= :maxPrice')->setParameter('maxPrice', $maxPrice);
        }

        match ($sort) {
            'price_asc' => $qb->orderBy('effectivePrice', 'ASC'),
            'price_desc' => $qb->orderBy('effectivePrice', 'DESC'),
            'name' => $qb->orderBy('p.name', 'ASC'),
            default => $qb->orderBy('p.createdAt', 'DESC'),
        };

        return $qb->addOrderBy('p.id', 'DESC')->getQuery();
    }

    /** @return Product[] */
    public function findFeatured(int $limit = 8): array
    {
        return $this->activeProducts()
            ->andWhere('p.isFeatured = true')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return Product[] */
    public function findOnSale(): array
    {
        return $this->activeProducts()
            ->andWhere('p.salePrice IS NOT NULL AND p.salePrice < p.price')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Product[] In stock but at or below the product's threshold, lowest first */
    public function findLowStock(): array
    {
        return $this->notArchived()
            ->andWhere('p.stock > 0 AND p.stock <= p.lowStockThreshold')
            ->orderBy('p.stock', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Product[] */
    public function findOutOfStock(): array
    {
        return $this->notArchived()
            ->andWhere('p.stock <= 0')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<array{product: Product, sold: int}> */
    public function findBestSellers(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p AS product', 'SUM(i.quantity) AS sold')
            ->join(OrderItem::class, 'i', 'WITH', 'i.product = p')
            ->join('i.order', 'o')
            ->andWhere('o.status <> :cancelled')
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->groupBy('p.id')
            ->orderBy('sold', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['product' => $row['product'], 'sold' => (int) $row['sold']],
            $rows,
        );
    }

    /**
     * @return Product[] Active products at least $days old with no non-cancelled sale in that period
     */
    public function findSlowMoving(int $days = 30): array
    {
        return $this->activeProducts()
            ->andWhere('p.createdAt < :since')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT i.id FROM %s i JOIN i.order o WHERE i.product = p AND o.createdAt >= :since AND o.status <> :cancelled)',
                OrderItem::class,
            ))
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Product[] */
    public function findRelated(Product $product, int $limit = 4): array
    {
        return $this->activeProducts()
            ->andWhere('p.category = :category')
            ->andWhere('p <> :product')
            ->setParameter('category', $product->getCategory())
            ->setParameter('product', $product)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Stock on hand valued at cost price, as a decimal string. */
    public function inventoryValue(): string
    {
        $value = $this->createQueryBuilder('p')
            ->select('SUM(p.stock * p.costPrice)')
            ->andWhere('p.stock > 0')
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $value, 2, '.', '');
    }

    public function findActiveBySlug(string $slug): ?Product
    {
        return $this->findOneBy(['slug' => $slug, 'status' => Product::STATUS_ACTIVE]);
    }

    /**
     * Locks product rows until the current transaction ends and reloads their latest values.
     * Sorting by id means two transactions always lock in the same order, which prevents deadlocks.
     *
     * @param int[] $ids
     *
     * @return Product[]
     */
    public function findForUpdate(array $ids): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /**
     * Units and revenue per product from line items, excluding cancelled and refunded orders.
     *
     * @return list<array{id: int, name: string, sku: string, units: string, revenue: string}>
     */
    public function revenueByProduct(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id', 'p.name', 'p.sku', 'SUM(i.quantity) AS units', 'SUM(i.quantity * i.unitPrice) AS revenue')
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
            ->groupBy('p.id', 'p.name', 'p.sku')
            ->orderBy('revenue', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    private function activeProducts(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :active')
            ->setParameter('active', Product::STATUS_ACTIVE);
    }

    private function notArchived(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status <> :archived')
            ->setParameter('archived', Product::STATUS_ARCHIVED);
    }
}
