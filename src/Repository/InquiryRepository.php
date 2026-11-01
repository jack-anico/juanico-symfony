<?php

namespace App\Repository;

use Doctrine\ORM\Query;
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

    /** Returns the query so controllers can paginate it; a null status lists everything. */
    public function listQuery(?string $status = null): Query
    {
        $qb = $this->createQueryBuilder('i')->orderBy('i.createdAt', 'DESC')->addOrderBy('i.id', 'DESC');
        if (null !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery();
    }

    /** @return Inquiry[] Newest first */
    public function findByStatus(string $status): array
    {
        return $this->findBy(['status' => $status], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
