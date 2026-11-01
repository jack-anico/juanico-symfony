<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @param array<string, mixed>|null $detail Queues the row; the caller's flush saves it with the audited change */
    public function record(User $admin, string $action, string $entityType, int $entityId, ?array $detail = null): void
    {
        $this->getEntityManager()->persist((new AuditLog())
            ->setAdmin($admin)
            ->setAction($action)
            ->setEntityType($entityType)
            ->setEntityId($entityId)
            ->setDetail($detail)
            ->setCreatedAt(new \DateTimeImmutable()));
    }

    /** @return AuditLog[] Newest first */
    public function findRecent(int $limit = 100): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
