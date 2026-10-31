<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Revenue figures exclude cancelled and refunded orders. Date ranges are inclusive.
 *
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function totalRevenueBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $total = $this->revenueOrdersBetween($from, $to)
            ->select('SUM(o.total)')
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $total, 2, '.', '');
    }

    public function countBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function averageOrderValue(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $average = $this->revenueOrdersBetween($from, $to)
            ->select('AVG(o.total)')
            ->getQuery()
            ->getSingleScalarResult();

        return number_format((float) $average, 2, '.', '');
    }

    /** @return array<string, int> Order count keyed by status */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status', 'COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        return array_map('intval', array_column($rows, 'total', 'status'));
    }

    /** @return Order[] */
    public function findPendingOlderThan(int $hours): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :pending')
            ->andWhere('o.createdAt < :cutoff')
            ->setParameter('pending', Order::STATUS_PENDING)
            ->setParameter('cutoff', new \DateTimeImmutable(sprintf('-%d hours', $hours)))
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Returns the query so controllers can paginate it. */
    public function findForCustomer(User $user, ?string $status = null): Query
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.customer = :customer')
            ->setParameter('customer', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC');

        if (null !== $status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery();
    }

    /**
     * Native SQL because DQL has no DATE() function.
     *
     * @return list<array{day: string, orders: int, revenue: string}>
     */
    public function salesByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(created_at) AS day, COUNT(*) AS orders, SUM(total) AS revenue
             FROM `order`
             WHERE created_at BETWEEN :from AND :to AND status <> :cancelled AND payment_status <> :refunded
             GROUP BY DATE(created_at)
             ORDER BY day ASC',
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'cancelled' => Order::STATUS_CANCELLED,
                'refunded' => Order::PAYMENT_REFUNDED,
            ],
        );

        return array_map(
            static fn (array $row): array => ['day' => $row['day'], 'orders' => (int) $row['orders'], 'revenue' => $row['revenue']],
            $rows,
        );
    }

    /** @return float Share of all orders that were cancelled, from 0 to 1 */
    public function cancellationRate(): float
    {
        $counts = $this->countByStatus();
        $total = array_sum($counts);

        return 0 === $total ? 0.0 : ($counts[Order::STATUS_CANCELLED] ?? 0) / $total;
    }

    /** @return float|null Average hours from order to delivery, or null with no deliveries */
    public function averageFulfillmentHours(): ?float
    {
        $minutes = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, delivered_at)) FROM `order` WHERE delivered_at IS NOT NULL',
        );

        return null === $minutes ? null : (float) $minutes / 60;
    }

    private function revenueOrdersBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.createdAt BETWEEN :from AND :to')
            ->andWhere('o.status <> :cancelled')
            ->andWhere('o.paymentStatus <> :refunded')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->setParameter('refunded', Order::PAYMENT_REFUNDED);
    }
}
