<?php

namespace App\DataFixtures;

use App\Entity\PlatformSetting;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PlatformSettingFixtures extends Fixture
{
    private const SETTINGS = [
        'min_order_amount' => ['500', 'Smallest subtotal accepted at checkout, in pesos'],
        'shipping_fee' => ['150', 'Flat delivery fee, in pesos'],
        'free_shipping_threshold' => ['5000', 'Subtotal at which shipping becomes free, in pesos'],
        'pending_order_escalation_hours' => ['24', 'Hours before a pending order is flagged on the dashboard'],
        'shop_open' => ['1', '1 accepts orders, 0 closes checkout'],
    ];

    public function load(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(PlatformSetting::class);

        // Existing values are kept, so this can run with --append without losing admin changes.
        foreach (self::SETTINGS as $key => [$value, $description]) {
            if (null !== $repository->findOneBy(['settingKey' => $key])) {
                continue;
            }

            $manager->persist((new PlatformSetting())
                ->setSettingKey($key)
                ->setSettingValue($value)
                ->setDescription($description));
        }

        $manager->flush();
    }
}
