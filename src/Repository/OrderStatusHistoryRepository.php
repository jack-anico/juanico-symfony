<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderStatusHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderStatusHistory>
 */
class OrderStatusHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderStatusHistory::class);
    }

    /** @return OrderStatusHistory[] Oldest first; id breaks ties between rows from the same second */
    public function findForOrder(Order $order): array
    {
        return $this->findBy(['order' => $order], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }
}
