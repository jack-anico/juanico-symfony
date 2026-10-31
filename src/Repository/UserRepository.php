<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** New customer accounts in an inclusive date range; admins are not counted. */
    public function countNewBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt BETWEEN :from AND :to')
            ->andWhere('u.roles NOT LIKE :admin')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('admin', '%ROLE_ADMIN%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return User[] Users with a non-cancelled order in the last $days days */
    public function findActiveCustomers(int $days = 30): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere(sprintf(
                'EXISTS (SELECT o.id FROM %s o WHERE o.customer = u AND o.createdAt >= :since AND o.status <> :cancelled)',
                Order::class,
            ))
            ->setParameter('since', new \DateTimeImmutable(sprintf('-%d days', $days)))
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<array{user: User, orderCount: int, totalSpent: string}> */
    public function topBuyers(int $limit = 10): array
    {
        $rows = $this->createQueryBuilder('u')
            ->select('u AS user', 'COUNT(o.id) AS orderCount', 'SUM(o.total) AS totalSpent')
            ->join(Order::class, 'o', 'WITH', 'o.customer = u')
            ->andWhere('o.status <> :cancelled')
            ->andWhere('o.paymentStatus <> :refunded')
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->setParameter('refunded', Order::PAYMENT_REFUNDED)
            ->groupBy('u.id')
            ->orderBy('totalSpent', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'user' => $row['user'],
                'orderCount' => (int) $row['orderCount'],
                'totalSpent' => $row['totalSpent'],
            ],
            $rows,
        );
    }

    /** @return float Share of buying customers with two or more non-cancelled orders, from 0 to 1 */
    public function repeatCustomerRate(): float
    {
        $orderCounts = $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->andWhere('o.status <> :cancelled')
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->groupBy('o.customer')
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $orderCounts) {
            return 0.0;
        }

        $repeat = array_filter($orderCounts, static fn ($count): bool => (int) $count >= 2);

        return count($repeat) / count($orderCounts);
    }
}
