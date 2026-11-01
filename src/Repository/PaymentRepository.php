<?php

namespace App\Repository;

use Doctrine\ORM\Query;
use App\Entity\Order;
use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /** @return Payment[] Newest first; a null filter matches everything */
    public function findByMethodAndStatus(?string $method = null, ?string $status = null): array
    {
        return $this->listQuery($method, $status)->getResult();
    }

    /** Returns the query so controllers can paginate it. */
    public function listQuery(?string $method = null, ?string $status = null): Query
    {
        $qb = $this->withOrder()->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC');

        if (null !== $method) {
            $qb->andWhere('p.method = :method')->setParameter('method', $method);
        }
        if (null !== $status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery();
    }

    /** @return Payment[] Bank transfers waiting for an admin, oldest first */
    public function findPendingBankTransfers(): array
    {
        return $this->withOrder()
            ->andWhere('p.method = :bank')
            ->andWhere('p.status = :pending')
            ->andWhere('o.status <> :cancelled')
            ->setParameter('bank', Payment::METHOD_BANK)
            ->setParameter('pending', Payment::STATUS_PENDING)
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Payment[] Cash on delivery orders that arrived but are not yet recorded as collected */
    public function findCollectableCod(): array
    {
        return $this->withOrder()
            ->andWhere('p.method = :cod')
            ->andWhere('p.status = :pending')
            ->andWhere('o.status = :delivered')
            ->setParameter('cod', Payment::METHOD_COD)
            ->setParameter('pending', Payment::STATUS_PENDING)
            ->setParameter('delivered', Order::STATUS_DELIVERED)
            ->orderBy('o.deliveredAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Payment[] Paid payments on cancelled orders, which an admin must refund */
    public function findAwaitingRefund(): array
    {
        return $this->withOrder()
            ->andWhere('p.status = :paid')
            ->andWhere('o.status = :cancelled')
            ->setParameter('paid', Payment::STATUS_PAID)
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->orderBy('p.paidAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function totalPaidBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $total = $this->createQueryBuilder('p')
            ->select('SUM(p.amount)')
            ->andWhere('p.status = :paid')
            ->andWhere('p.paidAt BETWEEN :from AND :to')
            ->setParameter('paid', Payment::STATUS_PAID)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return $total ?? '0.00';
    }

    private function withOrder(): QueryBuilder
    {
        return $this->createQueryBuilder('p')->addSelect('o')->join('p.order', 'o');
    }
}
