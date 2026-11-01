<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\PaymentStatusHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentStatusHistory>
 */
class PaymentStatusHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentStatusHistory::class);
    }

    /** @return PaymentStatusHistory[] Oldest first; id breaks ties between rows from the same second */
    public function findForPayment(Payment $payment): array
    {
        return $this->findBy(['payment' => $payment], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
