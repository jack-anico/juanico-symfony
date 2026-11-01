<?php

namespace App\Repository;

use App\Entity\Address;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /** @return Address[] Default address first */
    public function findByOwner(User $user): array
    {
        return $this->findBy(['owner' => $user], ['isDefault' => 'DESC', 'id' => 'ASC']);
    }

    /** Keeps a single default address by unsetting the owner's others. */
    public function clearDefaultExcept(Address $address): void
    {
        $this->getEntityManager()->createQuery(sprintf(
            'UPDATE %s a SET a.isDefault = false WHERE a.owner = :owner AND a.id <> :id',
            Address::class,
        ))
            ->setParameter('owner', $address->getOwner())
            ->setParameter('id', $address->getId())
            ->execute();
    }
}
