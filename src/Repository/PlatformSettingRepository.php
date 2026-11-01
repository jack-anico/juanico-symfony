<?php

namespace App\Repository;

use App\Entity\PlatformSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlatformSetting>
 */
class PlatformSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlatformSetting::class);
    }

    /** A missing setting is a deployment error, so it fails instead of guessing a money value. */
    public function getValue(string $key): string
    {
        $setting = $this->findOneBy(['settingKey' => $key])
            ?? throw new \RuntimeException(sprintf('Platform setting "%s" is missing. Load PlatformSettingFixtures.', $key));

        return (string) $setting->getSettingValue();
    }

    /** @return numeric-string A two-decimal string for bcmath */
    public function getDecimal(string $key): string
    {
        return bcadd($this->getValue($key), '0', 2);
    }

    public function getInt(string $key): int
    {
        return (int) $this->getValue($key);
    }
}
