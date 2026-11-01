<?php

namespace App\Repository;

use App\Entity\OrderStatusHistory;
use App\Entity\Payment;
use App\Entity\Address;
use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Revenue counts only delivered orders with a paid payment. Date ranges are inclusive.
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

        return bcadd((string) $total, '0', 2);
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

        return bcadd((string) $average, '0.005', 2);
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

    /** Every order for the admin table. Returns the query so controllers can paginate it. */
    public function adminListQuery(): Query
    {
        return $this->createQueryBuilder('o')->orderBy('o.createdAt', 'DESC')->addOrderBy('o.id', 'DESC')->getQuery();
    }

    public function findByIdempotencyKey(string $key): ?Order
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }

    /** @return OrderStatusHistory[] Oldest first; id breaks ties between rows from the same second */
    public function statusHistory(Order $order): array
    {
        return $this->getEntityManager()->getRepository(OrderStatusHistory::class)->findForOrder($order);
    }

    public function isAddressUsed(Address $address): bool
    {
        return $this->count(['shippingAddress' => $address]) > 0;
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
            'SELECT DATE(o.created_at) AS day, COUNT(*) AS orders, SUM(o.total) AS revenue
             FROM `order` o
             JOIN payment p ON p.order_id = o.id
             WHERE o.created_at BETWEEN :from AND :to AND o.status = :delivered AND p.status = :paid
             GROUP BY DATE(o.created_at)
             ORDER BY day ASC',
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'delivered' => Order::STATUS_DELIVERED,
                'paid' => Payment::STATUS_PAID,
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
            ->join('o.payment', 'pay')
            ->andWhere('o.status = :delivered')
            ->andWhere('pay.status = :paid')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('delivered', Order::STATUS_DELIVERED)
            ->setParameter('paid', Payment::STATUS_PAID);
    }
}
