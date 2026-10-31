<?php

namespace App\Repository;

use App\Entity\Inquiry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Inquiry>
 */
class InquiryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inquiry::class);
    }

    public function countUnresolved(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.status <> :resolved')
            ->setParameter('resolved', Inquiry::STATUS_RESOLVED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return Inquiry[] Newest first */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
