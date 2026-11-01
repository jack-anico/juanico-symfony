<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminUserFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setEmail('admin@juanico.test');
        $admin->setFullName('Juanico Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setIsActive(true);
        $admin->setIsVerified(true);
        $admin->setCreatedAt(new \DateTimeImmutable());
        // Local development credential only; never load fixtures in production.
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'Admin123!'));

        $manager->persist($admin);
        $manager->flush();

        $this->addReference('admin-user', $admin);
    }
}
