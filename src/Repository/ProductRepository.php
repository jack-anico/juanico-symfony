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
     * With search words and no chosen sort, the best matches come first.
     */
    public function search(
        ?string $q = null,
        ?int $categoryId = null,
        ?int $brandId = null,
        ?string $minPrice = null,
        ?string $maxPrice = null,
        ?string $sort = null,
        bool $onSaleOnly = false,
    ): Query {
        $qb = $this->activeProducts()
            ->addSelect('COALESCE(p.salePrice, p.price) AS HIDDEN effectivePrice')
            ->join('p.category', 'c');

        $terms = self::searchTerms($q);
        $this->matchTerms($qb, $terms);

        // Only symbols, such as "%": nothing to look for, which is not the same as no search
        if (!$terms && '' !== trim((string) $q)) {
            $qb->andWhere('1 = 0');
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

        if ($onSaleOnly) {
            $qb->andWhere('p.salePrice IS NOT NULL AND p.salePrice < p.price');
        }

        match ($sort) {
            'price_asc' => $qb->orderBy('effectivePrice', 'ASC'),
            'price_desc' => $qb->orderBy('effectivePrice', 'DESC'),
            'name' => $qb->orderBy('p.name', 'ASC'),
            'newest' => $qb->orderBy('p.createdAt', 'DESC'),
            default => $terms ? $qb->orderBy('relevance', 'DESC')->addOrderBy('p.name', 'ASC') : $qb->orderBy('p.createdAt', 'DESC'),
        };

        return $qb->addOrderBy('p.id', 'DESC')->getQuery();
    }

    /**
     * The best matches for the search box's live suggestions.
     *
     * @return Product[]
     */
    public function suggest(string $q, int $limit = 5): array
    {
        $terms = self::searchTerms($q);
        if (!$terms) {
            return [];
        }

        $qb = $this->activeProducts()->join('p.category', 'c')->addSelect('b');
        $this->matchTerms($qb, $terms);

        return $qb->orderBy('relevance', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * A corrected version of a search that found nothing, such as "stanley hammr" to "stanley hammer".
     * Null when no close spelling exists or the corrected search also finds nothing.
     */
    public function correctSpelling(string $q): ?string
    {
        $terms = self::searchTerms($q);
        if (!$terms) {
            return null;
        }

        $vocabulary = [];
        $rows = $this->activeProducts()
            ->select('p.name AS product, b.name AS brand, c.name AS category')
            ->join('p.category', 'c')
            ->leftJoin('p.brand', 'b')
            ->getQuery()
            ->getScalarResult();
        foreach ($rows as $row) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(implode(' ', $row)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if (mb_strlen($word) >= 3) {
                    $vocabulary[$word] = true;
                }
            }
        }
        $vocabulary = array_keys($vocabulary);
        sort($vocabulary);

        $corrected = array_map(static function (string $term) use ($vocabulary): string {
            if (in_array($term, $vocabulary, true)) {
                return $term;
            }

            // Short words change meaning quickly, so they only get one edit
            $best = $term;
            $bestDistance = mb_strlen($term) <= 4 ? 2 : 3;
            foreach ($vocabulary as $word) {
                $distance = levenshtein($term, $word);
                if ($distance < $bestDistance) {
                    $best = $word;
                    $bestDistance = $distance;
                }
            }

            return $best;
        }, $terms);

        if ($corrected === $terms) {
            return null;
        }

        $correction = implode(' ', $corrected);

        return $this->search($correction)->setMaxResults(1)->getResult() ? $correction : null;
    }

    /** Every product for the admin table. Returns the query so controllers can paginate it. */
    public function adminListQuery(): Query
    {
        return $this->createQueryBuilder('p')->orderBy('p.id', 'DESC')->getQuery();
    }

    public function countActive(): int
    {
        return (int) $this->activeProducts()->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
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

        return bcadd((string) $value, '0', 2);
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

    /**
     * Splits a search into lowercase words, with plurals made singular so "hammers" finds "Hammer".
     *
     * @return string[]
     */
    private static function searchTerms(?string $q): array
    {
        $words = preg_split('/[^\p{L}\p{N}.\-]+/u', mb_strtolower(mb_substr((string) $q, 0, 100)), -1, PREG_SPLIT_NO_EMPTY);
        $terms = [];
        foreach ($words as $word) {
            $word = trim($word, '.-');
            if (mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss')) {
                $word = mb_substr($word, 0, -1);
            }
            if ('' !== $word) {
                $terms[] = $word;
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, 6);
    }

    /**
     * Every word must match somewhere. A hidden "relevance" score adds up where each word matched,
     * with the product name counting most and the description least.
     *
     * @param string[] $terms
     */
    private function matchTerms(QueryBuilder $qb, array $terms): void
    {
        if (!$terms) {
            return;
        }

        $qb->leftJoin('p.brand', 'b')->leftJoin('c.parent', 'pc');

        $scores = [];
        foreach ($terms as $i => $term) {
            $escaped = addcslashes($term, '%_\\');
            $qb->andWhere("p.name LIKE :any$i OR p.sku LIKE :any$i OR b.name LIKE :any$i OR c.name LIKE :any$i OR pc.name LIKE :any$i OR p.description LIKE :any$i")
                ->setParameter("any$i", "%$escaped%")
                ->setParameter("start$i", "$escaped%")
                ->setParameter("word$i", "% $escaped%");

            $scores[] = "(CASE WHEN p.name LIKE :start$i THEN 10"
                ." WHEN p.name LIKE :word$i THEN 8"
                ." WHEN p.name LIKE :any$i THEN 6"
                ." WHEN p.sku LIKE :any$i THEN 6"
                ." WHEN b.name LIKE :any$i THEN 5"
                ." WHEN c.name LIKE :any$i THEN 4"
                ." WHEN pc.name LIKE :any$i THEN 3"
                .' ELSE 1 END)';
        }

        $qb->addSelect(implode(' + ', $scores).' AS HIDDEN relevance');
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
