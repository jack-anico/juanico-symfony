<?php

namespace App\Tests;

use App\Entity\PlatformSetting;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthTest extends WebTestCase
{
    private const PASSWORD = 'Secret123!';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager()->createQuery('DELETE FROM '.User::class)->execute();
        $this->entityManager()->createQuery('DELETE FROM '.PlatformSetting::class)->execute();
        $this->entityManager()->persist((new PlatformSetting())->setSettingKey('pending_order_escalation_hours')->setSettingValue('24'));
        $this->entityManager()->flush();
    }

    public function testRegistrationCreatesActiveUnverifiedCustomerAndSendsEmail(): void
    {
        $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $this->client->submitForm('Register', [
            'registration_form[fullName]' => 'Juan Dela Cruz',
            'registration_form[phone]' => '09171234567',
            'registration_form[email]' => 'juan@example.test',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertEmailCount(1);
        $this->assertResponseRedirects('/login');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'juan@example.test']);
        $this->assertNotNull($user);
        $this->assertSame('Juan Dela Cruz', $user->getFullName());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isVerified());
        $this->assertNotNull($user->getCreatedAt());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testRegistrationRequiresFullName(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', [
            'registration_form[fullName]' => '',
            'registration_form[email]' => 'nameless@example.test',
            'registration_form[plainPassword]' => self::PASSWORD,
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testCustomerLoginRedirectsToHome(): void
    {
        $this->createUser('customer@example.test');
        $this->logInThroughForm('customer@example.test');

        $this->assertResponseRedirects('/');
    }

    public function testAdminLoginRedirectsToDashboard(): void
    {
        $this->createUser('admin@example.test', ['ROLE_ADMIN']);
        $this->logInThroughForm('admin@example.test');

        $this->assertResponseRedirects('/admin');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createUser('customer@example.test');
        $this->logInThroughForm('customer@example.test', 'wrong-password');

        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousVisitorIsSentToLoginFromAdmin(): void
    {
        $this->client->request('GET', '/admin');

        $this->assertResponseRedirects('/login');
    }

    public function testCustomerIsForbiddenFromAdmin(): void
    {
        $this->client->loginUser($this->createUser('customer@example.test'));
        $this->client->request('GET', '/admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCanOpenDashboard(): void
    {
        $this->client->loginUser($this->createUser('admin@example.test', ['ROLE_ADMIN']));
        $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Test User');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        $user->setCreatedAt(new \DateTimeImmutable());
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));

        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function logInThroughForm(string $email, string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['email' => $email, 'password' => $password]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
