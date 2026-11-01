<?php

namespace App\Repository;

use App\Entity\DeliveryZone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryZone>
 */
class DeliveryZoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryZone::class);
    }

    /**
     * First active zone covering the province or, because zones may list cities, the city.
     * Matching happens in PHP because DQL has no JSON functions; the zone list is small.
     */
    public function findActiveForProvince(string $province, ?string $city = null): ?DeliveryZone
    {
        $wanted = array_filter([$this->normalize($province), null === $city ? null : $this->normalize($city)]);

        foreach ($this->findBy(['isActive' => true], ['id' => 'ASC']) as $zone) {
            $covered = array_map(fn (string $place): string => $this->normalize($place), $zone->getProvinces());
            if ([] !== array_intersect($wanted, $covered)) {
                return $zone;
            }
        }

        return null;
    }

    private function normalize(string $place): string
    {
        return mb_strtolower(trim($place));
    }
}
